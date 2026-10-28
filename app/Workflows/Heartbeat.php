<?php

namespace App\Workflows;

use App\Models\Setting;
use App\Services\Install;
use App\Simulation\Simulation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Says, every tick, that this install's schedule is alive — to the page (the
 * last tick, kept here) and to a Healthchecks.io-style service off this
 * machine, which texts when the pings stop or arrive at /fail. Its silence
 * alert is the point: it is what catches the Mac being asleep, off or
 * offline, when nothing here can send anything at all.
 *
 * /fail means the machinery is broken (no browser, the command crashed) —
 * never that a site failed its workflow; that is the workflow's own alert.
 */
class Heartbeat
{
    /** The schedule's last tick (tests:heartbeat), which pages and reports read. */
    public const LAST_TICK = 'schedule.last_tick';

    private const URL_CACHE = 'workflows.heartbeat_url';

    private const NONE = '-';

    public function __construct(private Simulation $simulation) {}

    public function beat(bool $healthy, string $note): void
    {
        Setting::set(self::LAST_TICK, now()->toIso8601String());

        $url = $this->url();

        if ($url === null) {
            return;
        }

        try {
            Http::timeout(10)->withBody($note, 'text/plain')->post(rtrim($url, '/').($healthy ? '' : '/fail'));
        } catch (Throwable) {
            // An unreachable heartbeat service: its own silence alert covers it.
        }
    }

    public static function lastTick(): ?Carbon
    {
        $at = Setting::get(self::LAST_TICK);

        return is_string($at) ? Carbon::parse($at) : null;
    }

    /**
     * The URL from config when set, else from keys. Bitwarden is asked at
     * most hourly — every keys read lists the whole project — and the answer
     * (or that there is none yet) is held encrypted with this app's key,
     * never in plain text on disk.
     */
    public function url(): ?string
    {
        if (filled(config('workflows.heartbeat_url'))) {
            return config('workflows.heartbeat_url');
        }

        $cached = Cache::get(self::URL_CACHE);

        if (is_string($cached)) {
            try {
                $url = Crypt::decryptString($cached);

                return $url === self::NONE ? null : $url;
            } catch (Throwable) {
                Cache::forget(self::URL_CACHE);
            }
        }

        $url = $this->fromKeys();
        Cache::put(self::URL_CACHE, Crypt::encryptString($url ?? self::NONE), now()->addHour());

        return $url;
    }

    /**
     * <this install's host>__WORKFLOWS_HEARTBEAT_URL__local
     * (tests.dashcore.com__WORKFLOWS_HEARTBEAT_URL__local), read by keys in its
     * own checkout with a clean environment, so this app's .env never
     * reaches it. Null when keys is missing, cannot reach Bitwarden, or does
     * not hold it yet.
     */
    private function fromKeys(): ?string
    {
        $checkout = (string) config('workflows.keys_checkout');

        if ($checkout === '' || ! is_file($checkout.'/artisan')) {
            return null;
        }

        // Each install's check is its own, filed under its own host.
        $app = (string) (config('workflows.keys_app') ?: Install::host());
        $name = "{$app}__WORKFLOWS_HEARTBEAT_URL__local";

        try {
            $result = Process::path($checkout)
                ->env($this->simulation->cleanEnvironment())
                ->input((string) json_encode([['app' => $app, 'environment' => 'local', 'variable' => 'WORKFLOWS_HEARTBEAT_URL']]))
                ->timeout(60)
                ->run([(string) config('scanner.php'), 'artisan', 'keys:fleet', 'values']);
        } catch (Throwable) {
            return null;
        }

        $values = $result->successful() ? json_decode($result->output(), true) : null;
        $value = is_array($values) ? ($values[$name] ?? null) : null;

        return is_string($value) && str_starts_with($value, 'https://') ? $value : null;
    }
}
