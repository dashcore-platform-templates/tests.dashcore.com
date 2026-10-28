<?php

namespace App\Scanning;

use App\Services\Install;

/**
 * What this install needs from the Mac to do its work: the long-lived
 * launchd workers (never started from the schedule — launchd ends every
 * process a tick starts), the schedule tick, and the names it makes on the
 * host. fleets reads it (`tests:host-runtime --json`) when it builds or
 * updates a fleet's tests site, so no plist is written by hand.
 */
class HostRuntime
{
    public const QUEUE_LABEL = 'com.dashcore.queue-tests';

    public const SIMULATION_LABEL = 'com.dashcore.simulation-tests';

    /**
     * @return array{
     *     install: array{fleet: string, host: string, home: bool, path: string},
     *     workers: list<array{label: string, purpose: string, program_arguments: list<string>, working_directory: string, log: string, environment: array<string, string>}>,
     *     schedule: array{program_arguments: list<string>, every: string, nightly_scan: bool, nightly_scan_at: string, pull_request_scans: bool, test_builds: bool},
     *     scratch: array{database_prefix: string, example_database: string, workspace: string},
     *     slots: array{database: string, scans: int, pull_requests: int}
     * }
     */
    public function describe(): array
    {
        $workers = [];
        $queues = (int) config('scanner.workers', 2);

        for ($n = 1; $n <= max(1, $queues); $n++) {
            $workers[] = $this->worker(
                Install::label(self::QUEUE_LABEL, $n === 1 ? '' : '-'.$n),
                'Scans and builds of this fleet\'s apps, one at a time, each waiting for a host-wide scan slot.',
                ['queue:work', (string) config('scanner.queue.connection'), '--queue='.config('scanner.queue.queue').',builds', '--sleep=10', '--tries=1', '--timeout='.$this->longestJob(), '--max-time=3600'],
            );
        }

        if ($this->pullRequestScans()) {
            $workers[] = $this->worker(
                Install::label(self::QUEUE_LABEL, '-prs'),
                'Pull request scans, on their own slot so the nightly scans never hold a PR\'s status back.',
                ['queue:work', (string) config('scanner.queue.connection'), '--queue='.config('scanner.pull_requests.queue'), '--sleep=10', '--tries=1', '--timeout='.((int) config('scanner.timeout') + 1200), '--max-time=3600'],
            );
        }

        $workers[] = $this->worker(
            Install::label(self::SIMULATION_LABEL),
            'The Proving Ground: journeys walked on this fleet\'s stand-ins while its switch is on.',
            ['tests:simulate'],
        );

        return [
            'install' => [
                'fleet' => Install::fleet(),
                'host' => Install::host(),
                'home' => Install::isHome(),
                'path' => base_path(),
            ],
            'workers' => $workers,
            'schedule' => [
                'program_arguments' => [$this->php(), 'artisan', 'schedule:run'],
                'every' => 'minute',
                'nightly_scan' => (bool) config('scanner.nightly'),
                'nightly_scan_at' => (string) config('scanner.nightly_at'),
                'pull_request_scans' => $this->pullRequestScans(),
                'test_builds' => (bool) config('scanner.build.nightly'),
            ],
            'scratch' => [
                'database_prefix' => (string) config('scanner.database.prefix').Install::scoped(''),
                'example_database' => (string) config('scanner.database.prefix').Install::scoped('sales'),
                'workspace' => (string) config('scanner.workspace'),
            ],
            'slots' => [
                'database' => (string) config('database.connections.'.config('scanner.slots.connection').'.database'),
                'scans' => (int) config('scanner.slots.scans'),
                'pull_requests' => (int) config('scanner.slots.pull_requests'),
            ],
        ];
    }

    public function pullRequestScans(): bool
    {
        return (bool) config('scanner.pull_requests.enabled');
    }

    /**
     * @param  list<string>  $arguments
     * @return array{label: string, purpose: string, program_arguments: list<string>, working_directory: string, log: string, environment: array<string, string>}
     */
    private function worker(string $label, string $purpose, array $arguments): array
    {
        $path = (string) config('scanner.path');

        return [
            'label' => $label,
            'purpose' => $purpose,
            'program_arguments' => [$this->php(), 'artisan', ...$arguments],
            'working_directory' => base_path(),
            'log' => storage_path('logs/'.$label.'.log'),
            'environment' => $path === '' ? [] : ['PATH' => $path.':/usr/bin:/bin:/usr/sbin:/sbin'],
        ];
    }

    /** A worker's timeout must outlast its longest job: a build runs the suite up to twice. */
    private function longestJob(): int
    {
        $scan = (int) config('scanner.timeout') + 1200;
        $build = (int) config('scanner.build.timeout') + (int) config('scanner.timeout') * 2 + 1800;

        return max($scan, $build);
    }

    private function php(): string
    {
        return (string) config('scanner.php');
    }
}
