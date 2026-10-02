<?php

namespace Dashcore\Bridge\Console;

use Dashcore\Bridge\Crypto\BridgeHeaders;
use Dashcore\Bridge\Crypto\Keypair;
use Dashcore\Bridge\Identity\AppId;
use Dashcore\Bridge\Identity\IdentityResolver;
use Dashcore\Bridge\Models\BridgeIdentity;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Zero-paste enrollment. The only environment a fleet app needs is:
 *
 *     BRIDGE_FLEET_KEY=fleet_…
 *     BRIDGE_CONTROL_URL=https://api.dashcore.com
 *
 * Everything else — keypair, app id, credential id, the control plane's
 * public key — is generated here or learned from the enrollment handshake,
 * then stored encrypted in this app's own database. Safe to leave in the
 * deploy command: once connected it is a no-op.
 *
 * `--if-needed` is what belongs in a deploy script. Joining a fleet is
 * something an app does *if* it has been given a fleet key, so the two ways
 * that legitimately end in "not enrolled" — no key configured, and a control
 * plane that did not answer — report and exit 0 rather than failing a deploy
 * over them. The app comes up unenrolled and enrolls on the next deploy or
 * on a manual run, which is the same state it would have been left in by not
 * running this at all. Run without the flag, every one of those is still a
 * failure, because there it was asked for directly.
 */
class ConnectCommand extends Command
{
    protected $signature = 'bridge:connect
        {--fresh : Discard the stored identity and enroll again}
        {--app-id= : Override the app ID (defaults to this app\'s hostname)}
        {--if-needed : Deploy mode — enroll if this app can and should, and never fail the deploy if it cannot}';

    protected $description = 'Self-enroll with the control plane using the fleet key and store the identity — nothing to paste';

    public function handle(IdentityResolver $identity): int
    {
        $appId = $this->option('app-id') ?: config('bridge.app_id') ?: AppId::derive();

        // The stored identity was minted under whatever APP_URL said at the
        // time. When that was wrong — a Cloud deploy still on its default
        // `*.laravel.cloud` host — fixing APP_URL changed nothing, because
        // "already connected" returned before the name was ever compared,
        // and a deploy never passes --fresh. A name that no longer matches is
        // a stale identity, so enrol again. The stored row is only replaced
        // once the hub accepts the new one: a refused attempt leaves the old
        // identity exactly as it was.
        $renamed = $identity->isComplete() && $identity->appId() !== $appId;

        // A stored identity is only this app's opinion. The hub is the one
        // that decides, and when its record is gone — removed, revoked, never
        // approved — the app went on saying "already connected" forever,
        // because nothing here ever asked. So ask, and enrol again on a no.
        $rejected = false;

        if ($identity->isComplete() && ! $this->option('fresh') && ! $renamed) {
            $standing = $this->hubStanding();

            if ($standing !== 'rejected') {
                $this->components->info($standing === 'accepted'
                    ? "Connected as [{$identity->appId()}], and the control plane accepts it — nothing to do."
                    : "Connected as [{$identity->appId()}]. The control plane did not answer, so this could not be confirmed.");

                if (str_ends_with((string) $identity->appId(), '.laravel.cloud')) {
                    $this->components->warn('That is a Laravel Cloud deployment hostname, not this app\'s name. Set APP_URL to the site\'s own domain and deploy again — it will re-enroll by itself.');
                }

                $this->line('  To throw this identity away and enrol from scratch: php artisan bridge:connect --fresh');

                return self::SUCCESS;
            }

            $rejected = true;
        }

        if ($renamed && ! $this->option('fresh')) {
            $this->components->info("Connected as [{$identity->appId()}], but this app is now [{$appId}] — enrolling again under the new name.");
        }

        if ($rejected) {
            $this->components->info("Stored identity [{$identity->appId()}] is not accepted by the control plane — removed, revoked or never approved there. Enrolling again.");
        }

        $fleetKey = config('bridge.fleet_key');
        $control = rtrim((string) config('bridge.control.url'), '/');

        if (blank($fleetKey) || blank($control)) {
            if ($this->option('if-needed')) {
                $this->components->info('No fleet key configured — this app is not joining a fleet. Nothing to do.');

                return self::SUCCESS;
            }

            $this->components->error('Connecting needs exactly two environment values:');
            $this->newLine();
            $this->line('BRIDGE_FLEET_KEY=fleet_…        # mint one on the control plane: /admin/connect');
            $this->line('BRIDGE_CONTROL_URL=https://api.dashcore.com');

            return self::FAILURE;
        }

        if (blank(config('app.key'))) {
            $this->components->error('APP_KEY is not set — the stored identity is encrypted with it. Run php artisan key:generate first.');

            return self::FAILURE;
        }

        $pair = Keypair::generate();

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('bridge.timeout'))
                ->post("{$control}/api/bridge/v1/enroll", [
                    'fleet_key' => $fleetKey,
                    'slug' => $appId,
                    'name' => (string) config('app.name'),
                    'url' => (string) config('app.url'),
                    // The control plane only distinguishes three tiers, so
                    // local/testing/anything-else all report as development.
                    'environment' => match (app()->environment()) {
                        'production' => 'production',
                        'staging' => 'staging',
                        default => 'development',
                    },
                    'credential_id' => "{$appId}-".now()->format('Y-m'),
                    'public_key' => $pair->publicKey,
                ]);
        } catch (ConnectionException $e) {
            return $this->give("Could not reach the control plane at {$control}: {$e->getMessage()}");
        }

        if ($response->status() === 409) {
            $this->line("  An admin must allow re-enrollment first: {$control}/admin/connect → [{$appId}] → Re-enroll.");

            return $this->give("The control plane already has a live service named [{$appId}].");
        }

        if ($response->failed()) {
            return $this->give('Enrollment failed: '.$response->json('error.message', $response->body()));
        }

        DB::transaction(function () use ($response, $appId, $pair, $control) {
            // Replace, never append: at most one identity is meaningful.
            BridgeIdentity::query()->delete();

            BridgeIdentity::create([
                'app_id' => $appId,
                'key_id' => $response->json('data.credential.credential_id'),
                'private_key' => $pair->privateKey,
                'control_url' => $control,
                'control_public_key' => $response->json('data.control_plane.public_key'),
            ]);
        });

        $identity->forget();

        $this->components->info("Enrolled as [{$appId}]. The identity is stored (encrypted) in this app's database — nothing to paste anywhere.");

        if ($response->json('data.service.status') === 'pending') {
            $this->line("  Awaiting approval: {$control}/admin/connect");
        }

        return self::SUCCESS;
    }

    /**
     * Whether the control plane still takes this app's stored identity, asked
     * with the one signed read every enrolled app is allowed: the manifest.
     * Only a 401 means no — the hub collapses every identity failure to that.
     * Anything else that is not a success (a timeout, a 5xx) proves nothing
     * either way, and a deploy must not throw away a working identity because
     * the hub was briefly down.
     *
     * @return 'accepted'|'rejected'|'unknown'
     */
    private function hubStanding(): string
    {
        $control = rtrim((string) config('bridge.control.url'), '/');

        if ($control === '') {
            return 'unknown';
        }

        $path = '/api/bridge/v1/manifest';

        try {
            $response = Http::withHeaders(BridgeHeaders::sign('GET', $path))
                ->acceptJson()
                ->timeout((int) config('bridge.timeout'))
                ->get($control.$path);
        } catch (ConnectionException) {
            return 'unknown';
        }

        return match (true) {
            $response->successful() => 'accepted',
            $response->status() === 401 => 'rejected',
            default => 'unknown',
        };
    }

    /**
     * An enrollment that did not happen: a failure when asked for directly,
     * a warning the deploy walks past when running with --if-needed.
     */
    private function give(string $message): int
    {
        if ($this->option('if-needed')) {
            $this->components->warn($message.' Not enrolled; the next deploy will try again.');

            return self::SUCCESS;
        }

        $this->components->error($message);

        return self::FAILURE;
    }
}
