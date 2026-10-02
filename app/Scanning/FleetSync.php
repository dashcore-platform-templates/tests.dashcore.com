<?php

namespace App\Scanning;

use App\Models\FleetApp;
use App\Models\Setting;
use Dashcore\Bridge\Fleet\Catalogue;
use Dashcore\Bridge\Keys\KeysetResolver;
use Dashcore\Bridge\Keys\ManifestKeysetResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reconciles fleet_apps against the install block of the control plane's
 * signed manifest — the same roster standards and ceo read. This app scans
 * its own fleet and nothing else, so there is no list to keep here.
 *
 * Anything short of a manifest with an install block leaves existing rows
 * alone and says why: the matrix keeps showing the last known fleet rather
 * than emptying because the hub was briefly unreachable.
 */
class FleetSync
{
    private const LAST_SYNC = 'fleet.last_sync';

    public function __construct(
        private readonly KeysetResolver $keys,
        private readonly CheckoutLocator $checkouts,
    ) {}

    /**
     * @return array{added: list<string>, updated: list<string>, removed: list<string>, error: ?string}
     */
    public function sync(): array
    {
        $result = $this->attempt();

        Setting::set(self::LAST_SYNC, [
            'at' => now()->toIso8601String(),
            'error' => $result['error'],
            'changes' => count($result['added']) + count($result['updated']) + count($result['removed']),
        ]);

        return $result;
    }

    /**
     * The last sync's outcome, for the matrix to explain an empty or stale fleet.
     *
     * @return array{at: string, error: ?string, changes: int}|null
     */
    public static function lastResult(): ?array
    {
        $last = Setting::get(self::LAST_SYNC);

        return is_array($last) ? $last : null;
    }

    /**
     * @return array{added: list<string>, updated: list<string>, removed: list<string>, error: ?string}
     */
    private function attempt(): array
    {
        $result = ['added' => [], 'updated' => [], 'removed' => [], 'error' => null];

        if (! $this->keys instanceof ManifestKeysetResolver) {
            $result['error'] = 'This app is not connected to the fleet yet (BRIDGE_DRIVER is not "control"), so there is no roster to read.';

            return $result;
        }

        try {
            $install = $this->keys->manifest()['install'] ?? null;
        } catch (Throwable $e) {
            Log::warning('Tests: fleet roster sync failed', ['error' => $e->getMessage()]);
            $result['error'] = 'The control plane could not be read: '.$e->getMessage();

            return $result;
        }

        $domain = strtolower(trim((string) ($install['domain'] ?? ''), '.'));
        $apps = $install['apps'] ?? null;

        if ($domain === '' || ! is_array($apps) || $apps === []) {
            $result['error'] = 'The fleet manifest carries no install block; the app list was left as it is.';

            return $result;
        }

        DB::transaction(function () use ($apps, $domain, &$result) {
            $seen = [];

            foreach ($apps as $key => $enrolledAs) {
                $key = strtolower(trim((string) $key));

                if ($key === '') {
                    continue;
                }

                $seen[] = $key;
                $attributes = $this->attributes($key, (string) $enrolledAs, $domain);

                $app = FleetApp::withTrashed()->where('key', $key)->first();

                if ($app === null) {
                    FleetApp::create($attributes + ['key' => $key, 'synced_at' => now()]);
                    $result['added'][] = $key;

                    continue;
                }

                if ($app->trashed()) {
                    $app->restore();
                    $result['added'][] = $key;
                }

                $app->fill($attributes);

                if ($app->isDirty()) {
                    $result['updated'][] = $key;
                }

                $app->synced_at = now();
                $app->save();
            }

            FleetApp::query()->whereNotIn('key', $seen)->get()->each(function (FleetApp $app) use (&$result) {
                $app->delete();
                $result['removed'][] = $app->key;
            });
        });

        return $result;
    }

    /**
     * @return array{host: string, name: string, group: ?string, repo: ?string, local_path: ?string}
     */
    private function attributes(string $key, string $enrolledAs, string $domain): array
    {
        $known = Catalogue::has($key);
        $host = $known
            ? Catalogue::host($key, $domain)
            : strtolower(Str::chopEnd(trim($enrolledAs), '.test'));

        return [
            'host' => $host,
            'name' => $this->name($key),
            'group' => $known ? Catalogue::app($key)['group'] : null,
            'repo' => $known ? Catalogue::app($key)['repo'] : null,
            'local_path' => $this->checkouts->find($host),
        ];
    }

    private const ACRONYMS = ['api', 'hr', 'ceo', 'mcp'];

    private function name(string $key): string
    {
        return match (true) {
            $key === 'delivery' => 'DCOS (Delivery)',
            $key === 'website' => 'Website',
            in_array($key, self::ACRONYMS, true) => strtoupper($key),
            default => Str::of($key)->replace(['_', '-'], ' ')->title()->toString(),
        };
    }
}
