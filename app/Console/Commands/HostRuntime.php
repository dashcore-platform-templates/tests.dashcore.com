<?php

namespace App\Console\Commands;

use App\Scanning\HostRuntime as Runtime;
use App\Scanning\WorkerCheck;
use Illuminate\Console\Command;

class HostRuntime extends Command
{
    protected $signature = 'tests:host-runtime
                            {--json : Print it as JSON, for Console and fleets to install}
                            {--verify : Exit 1 unless every listed worker is loaded in launchd and each switch given below matches config}
                            {--nightly= : With --verify: on or off, what SCANNER_NIGHTLY must be}
                            {--nightly-at= : When this fleet\'s nightly scan starts (HH:MM), as Console staggers it; with --verify, what SCANNER_NIGHTLY_AT must be}
                            {--pr-scans= : With --verify: on or off, what SCANNER_PR_SCANS must be (not the pause)}
                            {--slots= : The host-wide scan slots every install shares}
                            {--slots-dir= : Where Console keeps scan slots; tests holds its slots as Postgres advisory locks, so it is only reported}';

    protected $description = 'List the launchd workers, the schedule and the host names this install needs on the Mac';

    public function handle(Runtime $runtime, WorkerCheck $check): int
    {
        if ($this->option('verify')) {
            return $this->verify($check);
        }

        $nightlyAt = $this->option('nightly-at');
        $slots = $this->option('slots');
        $slotsDir = $this->option('slots-dir');

        if ($nightlyAt !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $nightlyAt) !== 1) {
            $this->components->error("--nightly-at must be a time as HH:MM, not \"{$nightlyAt}\".");

            return self::FAILURE;
        }

        if ($slots !== null && (filter_var($slots, FILTER_VALIDATE_INT) === false || (int) $slots < 1)) {
            $this->components->error("--slots must be a whole number of 1 or more, not \"{$slots}\".");

            return self::FAILURE;
        }

        $described = $runtime->describe([
            'nightly_at' => $nightlyAt,
            'slots' => $slots === null ? null : (int) $slots,
            'slots_dir' => $slotsDir === null || $slotsDir === '' ? null : (string) $slotsDir,
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($described, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $install = $described['install'];
        $this->components->info("tests for the {$install['fleet']} fleet ({$install['host']})".($install['home'] ? ', the home install' : ''));

        foreach ($described['launchd'] as $worker) {
            $this->components->twoColumnDetail($worker['label'], implode(' ', array_slice($worker['program_arguments'], 1)));
        }

        $schedule = $described['schedule'];
        $this->components->twoColumnDetail('Schedule', 'every minute: artisan schedule:run');
        $this->components->twoColumnDetail('Nightly scan', $schedule['nightly_scan'] ? $schedule['nightly_scan_at'] : 'off');
        $this->components->twoColumnDetail('Pull request scans', $schedule['pull_request_scans'] ? 'on' : 'off');
        $this->components->twoColumnDetail('Scratch databases', $described['scratch']['database_prefix'].'{app}');
        $this->components->twoColumnDetail('Host-wide slots', "{$described['slots']['scans']} scans, {$described['slots']['pull_requests']} PR scans");

        return self::SUCCESS;
    }

    /**
     * The machine check Console runs after switching scans back on: every
     * worker loaded, every switch it names as expected. Each problem is
     * one line, so the check's output says what is missing.
     */
    private function verify(WorkerCheck $check): int
    {
        $expected = [];

        foreach (['nightly' => 'nightly', 'pr-scans' => 'pull_request_scans'] as $option => $key) {
            $value = $this->option($option);

            if ($value === null) {
                continue;
            }

            if (! in_array($value, ['on', 'off'], true)) {
                $this->components->error("--{$option} takes on or off, not {$value}.");

                return self::INVALID;
            }

            $expected[$key] = $value === 'on';
        }

        if (($at = $this->option('nightly-at')) !== null) {
            if (! preg_match('/^\d{2}:\d{2}$/', (string) $at)) {
                $this->components->error("--nightly-at takes HH:MM, not {$at}.");

                return self::INVALID;
            }

            $expected['nightly_at'] = (string) $at;
        }

        $problems = [
            ...array_map(fn (string $label) => "{$label} is not loaded in launchd", $check->missingWorkers()),
            ...$check->switchMismatches($expected),
        ];

        if ($problems === []) {
            $this->line('Every worker is loaded'.($expected === [] ? '.' : ' and the schedule switches match.'));

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->line($problem);
        }

        return self::FAILURE;
    }
}
