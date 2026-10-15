<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Telemetry;

use Dashcore\Bridge\Facades\Bridge;
use Dashcore\Bridge\Fleet\PeerLocator;
use Dashcore\Bridge\Identity\IdentityResolver;
use Dashcore\Bridge\Models\ErrorGroup;
use Dashcore\Bridge\Models\RouteStat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Ships this app's closed error and route buckets to the collector.
 *
 * The same arrangement as `CallReporter`, for the same reason: no app can see
 * more than its own half of the fleet, so each one reports what only it knows
 * and the collector assembles the picture. What differs is the destination —
 * call reports go to the control plane, which owns "who may call whom", while
 * these go to `health`, which owns "is anything wrong".
 *
 * **Only closed windows are sent.** The current hour is still being written
 * to, and shipping it would either report a partial count as a final one or
 * require the collector to handle the same bucket arriving repeatedly with a
 * rising number. An hour that has ended will never change again, which makes
 * it a fact rather than a reading.
 *
 * What travels is the shape of the failure, never the failure's contents:
 * exception class, redacted message, file and line, route pattern, and counts.
 * See `Redactor` for what is stripped and why the stripping happens on write
 * rather than here.
 */
final class TelemetryReporter
{
    /**
     * Rows of each kind in one request. Bounded so an app that has not
     * reported in a week cannot post a novel — and kept under the collector's
     * own cap (health refuses more than 500 with a 422). A run's --limit is
     * the total it may send; it goes in requests of this size.
     */
    public const BATCH = 200;

    public function __construct(
        private readonly IdentityResolver $identity,
        private readonly Config $config,
    ) {}

    /**
     * Send closed windows to the collector, up to $limit of each kind, in
     * requests of at most BATCH. Stops at the first refusal and reports what
     * got through before it.
     *
     * @return array{errors: int, routes: int, skipped: string|null}
     */
    public function report(int $limit = self::BATCH): array
    {
        $nothing = ['errors' => 0, 'routes' => 0];

        if (! $this->config->enabled()) {
            return [...$nothing, 'skipped' => 'Telemetry is switched off in this app\'s config.'];
        }

        if (! $this->identity->isComplete()) {
            return [...$nothing, 'skipped' => 'This app has no bridge identity yet, so it cannot sign a report.'];
        }

        $closed = Carbon::now()->startOfHour();
        $sent = $nothing;

        while ($sent['errors'] < $limit || $sent['routes'] < $limit) {
            $errors = $this->unreported(ErrorGroup::query(), $closed, min(self::BATCH, $limit - $sent['errors']));
            $routes = $this->unreported(RouteStat::query(), $closed, min(self::BATCH, $limit - $sent['routes']));

            if ($errors->isEmpty() && $routes->isEmpty()) {
                break;
            }

            $skipped = $this->send($errors, $routes);

            if ($skipped !== null) {
                return [...$sent, 'skipped' => $skipped];
            }

            $sent['errors'] += $errors->count();
            $sent['routes'] += $routes->count();
        }

        return [...$sent, 'skipped' => null];
    }

    /**
     * One request. Null when the collector took it; otherwise why not.
     *
     * @param  Collection<int, ErrorGroup>  $errors
     * @param  Collection<int, RouteStat>  $routes
     */
    private function send(Collection $errors, Collection $routes): ?string
    {
        try {
            $response = Bridge::to($this->collector())->timeout(20)->post('/telemetry', [
                'errors' => $errors->map(fn (ErrorGroup $group) => [
                    // The app's own row id, so the collector can dedupe a
                    // retry rather than double-counting. A report that times
                    // out after the collector committed is the normal case.
                    'remote_id' => $group->id,
                    'fingerprint' => $group->fingerprint,
                    'level' => $group->level,
                    'exception' => $group->exception,
                    'message' => $group->message,
                    'file' => $group->file,
                    'line' => $group->line,
                    'context' => $group->context,
                    'count' => $group->count,
                    'window_start' => $group->window_start?->toIso8601String(),
                    'first_seen_at' => $group->first_seen_at?->toIso8601String(),
                    'last_seen_at' => $group->last_seen_at?->toIso8601String(),
                ])->values()->all(),

                'routes' => $routes->map(fn (RouteStat $stat) => [
                    'remote_id' => $stat->id,
                    'method' => $stat->method,
                    'route' => $stat->route,
                    'count' => $stat->count,
                    'slow_count' => $stat->slow_count,
                    'error_count' => $stat->error_count,
                    'mean_ms' => $stat->meanMs(),
                    'max_ms' => $stat->max_ms,
                    'window_start' => $stat->window_start?->toIso8601String(),
                ])->values()->all(),

                // So the collector can say "slow" in the reporting app's own
                // terms rather than imposing one threshold on thirteen apps
                // with very different jobs.
                'slow_request_ms' => $this->config->slowRequestMs(),
            ]);
        } catch (Throwable $e) {
            return 'Could not reach the collector: '.$e->getMessage();
        }

        if (! $response->successful()) {
            return 'The collector refused the report: HTTP '.$response->status();
        }

        // Marked only once the collector has it. The reverse order loses a
        // window whenever a report fails, and a gap is worse than a duplicate
        // the collector already knows how to drop.
        $now = Carbon::now();
        ErrorGroup::query()->whereIn('id', $errors->pluck('id'))->update(['reported_at' => $now]);
        RouteStat::query()->whereIn('id', $routes->pluck('id'))->update(['reported_at' => $now]);

        return null;
    }

    /**
     * Delete buckets that have been reported and are past the retention
     * window.
     *
     * The local copy exists to survive a collector that is down, not to be an
     * archive — `health` holds the history. Kept for a few days so a report
     * that failed all weekend still has something to send.
     */
    public function prune(): int
    {
        $before = Carbon::now()->subDays($this->config->retentionDays());

        return ErrorGroup::query()->whereNotNull('reported_at')->where('window_start', '<', $before)->delete()
            + RouteStat::query()->whereNotNull('reported_at')->where('window_start', '<', $before)->delete();
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Collection<int, T>
     */
    private function unreported($query, Carbon $closed, int $limit): Collection
    {
        return $query
            ->whereNull('reported_at')
            ->where('window_start', '<', $closed)
            ->orderBy('window_start')
            ->limit($limit)
            ->get();
    }

    /**
     * The collector's peer id in this environment.
     *
     * `config('bridge.telemetry.collector')` names a fleet app key (`health`);
     * PeerLocator turns it into the hostname the manifest knows it by.
     */
    public function collector(): string
    {
        return app(PeerLocator::class)->find($this->config->collector());
    }
}
