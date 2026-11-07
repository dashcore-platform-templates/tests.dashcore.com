<?php

namespace App\Simulation;

use App\Services\FleetExport;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps a stand-in fleet on the code its real fleet runs. A journey walked on
 * a stand-in proves something about clients only while each stand-in app is
 * on the release (or, for Dashcore's own apps, the commit) that app runs
 * live — fleets says which (`fleet:export`). `pin()` moves each checkout
 * there; `drift()` says which are not, for a journey run to record.
 *
 * A stand-in fleet stands in for its `stands_in_for` fleet, else the home
 * fleet (Dashcore's); an app for its `live_app`, else the app of the same key.
 *
 * The pinned commit is often no branch tip, so a pin puts the checkout on a
 * local branch of its own, `stand-in` (`git switch -C stand-in <sha>`): HEAD
 * is never detached and `main` is never moved. A checkout detached or on any
 * other branch is drift, even at the live commit.
 */
class StandInPins
{
    /** The local branch every pinned stand-in sits on. */
    public const BRANCH = 'stand-in';

    public function __construct(
        private readonly FleetExport $export,
        private readonly Simulation $simulation,
        private readonly StandInGuard $guard,
    ) {}

    /**
     * Each stand-in app with a checkout: what its fleet runs live and what the
     * checkout holds. `live` is null when fleets does not say; `live_sha`
     * when that release or commit is not in the checkout; `branch` the branch
     * the checkout is on, null when its HEAD is detached.
     *
     * @param  list<string>|null  $only  just these apps
     * @return list<array{app: string, checkout: string, live: ?string, live_sha: ?string, actual_sha: ?string, branch: ?string}>
     */
    public function survey(string $fleetKey, ?array $only = null): array
    {
        $fleet = (array) config("simulation.fleets.{$fleetKey}", []);
        $survey = [];

        foreach ((array) ($fleet['apps'] ?? []) as $key => $app) {
            $checkout = is_array($app) ? ($app['checkout'] ?? null) : null;

            if (! is_string($checkout) || ! is_dir($checkout) || ($only !== null && ! in_array($key, $only, true))) {
                continue;
            }

            $live = $this->export->live($this->standsInFor($fleet), (string) ($app['live_app'] ?? $key));
            $ref = $live['commit'] ?? (isset($live['release']) ? 'refs/tags/'.$live['release'] : null);

            $survey[] = [
                'app' => (string) $key,
                'checkout' => $checkout,
                'live' => $live['release'] ?? $live['commit'] ?? null,
                'live_sha' => $ref === null ? null : $this->sha($checkout, $ref),
                'actual_sha' => $ref === null ? null : $this->sha($checkout, 'HEAD'),
                'branch' => $ref === null ? null : $this->branch($checkout),
            ];
        }

        return $survey;
    }

    /**
     * The stand-ins not on what their fleet runs live, or not on the
     * `stand-in` branch (detached, or on main or any other branch). An app
     * fleets says nothing about cannot drift from it, so it is left out.
     *
     * @param  list<string>|null  $only
     * @return list<array{app: string, checkout: string, live: ?string, live_sha: ?string, actual_sha: ?string, branch: ?string}>
     */
    public function drift(string $fleetKey, ?array $only = null): array
    {
        return self::drifted($this->survey($fleetKey, $only));
    }

    /**
     * @param  list<array{app: string, checkout: string, live: ?string, live_sha: ?string, actual_sha: ?string, branch: ?string}>  $survey
     * @return list<array{app: string, checkout: string, live: ?string, live_sha: ?string, actual_sha: ?string, branch: ?string}>
     */
    public static function drifted(array $survey): array
    {
        return array_values(array_filter($survey, fn (array $app) => $app['live'] !== null
            && ($app['live_sha'] !== $app['actual_sha'] || ($app['branch'] ?? null) !== self::BRANCH)));
    }

    /**
     * Move every stand-in checkout of the fleet to what its real fleet runs:
     * fetch, put the checkout on the `stand-in` branch at that commit (never a
     * detached HEAD, main untouched), then install, migrate and build
     * whatever the move changed. A checkout with uncommitted changes is
     * someone's work and is left alone.
     *
     * @return list<array{app: string, status: string, live: ?string, detail: string}>
     */
    public function pin(string $fleetKey): array
    {
        $fleet = (array) config("simulation.fleets.{$fleetKey}", []);
        $results = [];

        foreach ((array) ($fleet['apps'] ?? []) as $key => $app) {
            $checkout = is_array($app) ? ($app['checkout'] ?? null) : null;
            $live = $this->export->live($this->standsInFor($fleet), (string) ($app['live_app'] ?? $key));
            $named = $live['release'] ?? $live['commit'] ?? null;
            $result = fn (string $status, string $detail) => ['app' => (string) $key, 'status' => $status, 'live' => $named, 'detail' => $detail];

            if (($refusal = $this->guard->checkoutRefusal(is_string($checkout) ? $checkout : null)) !== null) {
                $results[] = $result('refused', $refusal);

                continue;
            }

            if ($named === null) {
                $results[] = $result('unknown', 'fleets does not say what '.($app['live_app'] ?? $key).' runs in the '.$this->standsInFor($fleet).' fleet.');

                continue;
            }

            $results[] = $this->move((string) $checkout, $live, $result);
        }

        return $results;
    }

    /** @param  array<string, mixed>  $fleet */
    public function standsInFor(array $fleet): string
    {
        return (string) ($fleet['stands_in_for'] ?? config('install.home_fleet'));
    }

    /**
     * @param  array{release: ?string, commit: ?string, local: ?string}  $live
     * @param  callable(string, string): array{app: string, status: string, live: ?string, detail: string}  $result
     * @return array{app: string, status: string, live: ?string, detail: string}
     */
    private function move(string $checkout, array $live, callable $result): array
    {
        $git = fn (array $args, int $timeout = 120) => Process::path($checkout)->timeout($timeout)->run(['git', ...$args]);

        if (trim($git(['status', '--porcelain', '--untracked-files=no'])->output()) !== '') {
            return $result('refused', basename($checkout).' has uncommitted changes; it was left as it is.');
        }

        $git(['fetch', '--quiet', 'origin', '+refs/tags/*:refs/tags/*'], 300);

        if ($live['commit'] !== null && $this->sha($checkout, $live['commit']) === null) {
            $git(['fetch', '--quiet', 'origin', $live['commit']], 300);
        }

        $target = ($live['commit'] !== null ? $this->sha($checkout, $live['commit']) : null)
            ?? ($live['release'] !== null ? $this->sha($checkout, 'refs/tags/'.$live['release']) : null);

        if ($target === null) {
            return $result('refused', 'neither '.implode(' nor ', array_filter([$live['release'], $live['commit']])).' could be found in '.basename($checkout).'.');
        }

        $from = $this->sha($checkout, 'HEAD');
        $branch = $this->branch($checkout);

        if ($from === $target && $branch === self::BRANCH) {
            return $result('current', 'already on '.substr($target, 0, 7).'.');
        }

        $switch = $git(['switch', '--quiet', '--force-create', self::BRANCH, $target]);

        if ($switch->failed()) {
            return $result('failed', 'git switch failed: '.Str::limit(trim($switch->errorOutput()), 500));
        }

        if ($from === $target) {
            $was = $branch === null ? 'a detached HEAD' : "branch {$branch}";

            return $result('moved', 'moved from '.$was.' onto branch '.self::BRANCH.' at '.substr($target, 0, 7).'.');
        }

        $changed = $from === null ? ['composer.lock', 'database/migrations', 'package-lock.json'] : array_filter(explode("\n", $git(['diff', '--name-only', $from, $target])->output()));
        $touched = fn (string $prefix) => collect($changed)->contains(fn (string $file) => str_starts_with($file, $prefix));
        $steps = array_values(array_filter([
            $touched('composer.lock') ? [(string) config('scanner.composer'), 'install', '--no-interaction', '--prefer-dist'] : null,
            $touched('database/migrations') ? [(string) config('scanner.php'), 'artisan', 'migrate', '--force', '--no-interaction'] : null,
            $touched('package-lock.json') ? [(string) config('scanner.npm'), 'ci'] : null,
            $touched('package-lock.json') || $touched('resources/') || $touched('vite.config') ? [(string) config('scanner.npm'), 'run', 'build'] : null,
            [(string) config('scanner.php'), 'artisan', 'optimize:clear', '--no-interaction'],
        ]));

        foreach ($steps as $command) {
            try {
                $run = Process::path($checkout)->env($this->environment())->timeout(900)->run($command);
            } catch (Throwable $e) {
                return $result('failed', 'moved to '.substr($target, 0, 7).', but '.implode(' ', array_slice($command, 0, 3)).' failed: '.$e->getMessage());
            }

            if ($run->failed()) {
                return $result('failed', 'moved to '.substr($target, 0, 7).', but '.implode(' ', array_slice($command, 0, 3)).' failed: '.Str::limit(trim($run->errorOutput() ?: $run->output()), 500));
            }
        }

        return $result('moved', 'moved from '.substr((string) $from, 0, 7).' to '.substr($target, 0, 7).' on branch '.self::BRANCH.'.');
    }

    /** @return array<string, string|false> */
    private function environment(): array
    {
        $path = (string) config('scanner.path');

        return [
            ...$this->simulation->targetEnvironment(),
            ...($path === '' ? [] : ['PATH' => $path.':'.getenv('PATH')]),
        ];
    }

    /** The branch the checkout is on; null when its HEAD is detached. */
    private function branch(string $checkout): ?string
    {
        $result = Process::path($checkout)->run(['git', 'symbolic-ref', '--quiet', '--short', 'HEAD']);
        $branch = trim($result->output());

        return $result->successful() && $branch !== '' ? $branch : null;
    }

    private function sha(string $checkout, string $ref): ?string
    {
        $result = Process::path($checkout)->run(['git', 'rev-parse', '--verify', '--quiet', $ref.'^{commit}']);
        $sha = trim($result->output());

        return $result->successful() && $sha !== '' ? $sha : null;
    }
}
