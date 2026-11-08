<?php

namespace App\Scanning;

use App\Building\BuildRequester;
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
     * Console (FleetHost::hostRuntime) is the one reader of `workers`: a
     * name => artisan arguments map it writes one launchd agent per entry
     * from, under its own labels. The launchd detail tests would write
     * itself (labels, logs, PATH) stays under `launchd`. Console tells it
     * the fleet's nightly time and the host-wide slots; the slots stay
     * Postgres advisory locks (ScanSlots), so `slots_dir` is reported and
     * otherwise unused (REQ-683).
     *
     * @param  array{nightly_at?: ?string, slots?: ?int, slots_dir?: ?string}  $told
     * @return array{
     *     install: array{fleet: string, host: string, home: bool, path: string},
     *     workers: array<string, list<string>>,
     *     launchd: list<array{name: string, label: string, purpose: string, program_arguments: list<string>, working_directory: string, log: string, environment: array<string, string>}>,
     *     schedule: array{program_arguments: list<string>, every: string, nightly_scan: bool, nightly_scan_at: string, pull_request_scans: bool, test_builds: bool},
     *     scratch: array{database_prefix: string, example_database: string, workspace: string},
     *     slots: array{database: string, scans: int, pull_requests: int, slots_dir: ?string}
     * }
     */
    public function describe(array $told = []): array
    {
        $workers = [];
        $queues = (int) config('scanner.workers', 2);

        for ($n = 1; $n <= max(1, $queues); $n++) {
            $workers[] = $this->worker(
                $n === 1 ? 'scans' : 'scans-'.$n,
                Install::label(self::QUEUE_LABEL, $n === 1 ? '' : '-'.$n),
                'Scans and builds of this fleet\'s apps, one at a time, each waiting for a host-wide scan slot.',
                ['queue:work', (string) config('scanner.queue.connection'), '--queue='.config('scanner.queue.queue').',builds', '--sleep=10', '--tries=1', '--timeout='.$this->longestJob(), '--max-time=3600'],
            );
        }

        if ($this->pullRequestScans()) {
            $workers[] = $this->worker(
                'prs',
                Install::label(self::QUEUE_LABEL, '-prs'),
                'Pull request scans, on their own slot so the nightly scans never hold a PR\'s status back.',
                ['queue:work', (string) config('scanner.queue.connection'), '--queue='.config('scanner.pull_requests.queue'), '--sleep=10', '--tries=1', '--timeout='.((int) config('scanner.timeout') + 1200), '--max-time=3600'],
            );
        }

        $workers[] = $this->worker(
            'simulation',
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
            'workers' => collect($workers)->mapWithKeys(fn (array $w) => [$w['name'] => array_slice($w['program_arguments'], 2)])->all(),
            'launchd' => $workers,
            'schedule' => [
                'program_arguments' => [$this->php(), 'artisan', 'schedule:run'],
                'every' => 'minute',
                'nightly_scan' => (bool) config('scanner.nightly'),
                'nightly_scan_at' => (string) ($told['nightly_at'] ?? config('scanner.nightly_at')),
                'pull_request_scans' => $this->pullRequestScans(),
                'test_builds' => (bool) config('scanner.build.nightly') && BuildRequester::allowed(),
            ],
            'scratch' => [
                'database_prefix' => (string) config('scanner.database.prefix').Install::scoped(''),
                'example_database' => (string) config('scanner.database.prefix').Install::scoped('sales'),
                'workspace' => (string) config('scanner.workspace'),
            ],
            'slots' => [
                'database' => (string) config('database.connections.'.config('scanner.slots.connection').'.database'),
                'scans' => (int) ($told['slots'] ?? config('scanner.slots.scans')),
                'pull_requests' => (int) config('scanner.slots.pull_requests'),
                'slots_dir' => $told['slots_dir'] ?? null,
            ],
        ];
    }

    public function pullRequestScans(): bool
    {
        return PullRequestScans::allowed();
    }

    /**
     * @param  list<string>  $arguments
     * @return array{name: string, label: string, purpose: string, program_arguments: list<string>, working_directory: string, log: string, environment: array<string, string>}
     */
    private function worker(string $name, string $label, string $purpose, array $arguments): array
    {
        $path = (string) config('scanner.path');

        return [
            'name' => $name,
            'label' => $label,
            'purpose' => $purpose,
            'program_arguments' => [$this->php(), 'artisan', ...$arguments],
            'working_directory' => base_path(),
            'log' => storage_path('logs/'.$label.'.log'),
            'environment' => $path === '' ? [] : ['PATH' => $path.':/usr/bin:/bin:/usr/sbin:/sbin'],
        ];
    }

    /** A worker's timeout must outlast its longest job: a build runs the suite up to twice. */
    public function longestJob(): int
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
