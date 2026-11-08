<?php

namespace App\Console\Commands;

use App\Scanning\HostRuntime as Runtime;
use Illuminate\Console\Command;

class HostRuntime extends Command
{
    protected $signature = 'tests:host-runtime
                            {--json : Print it as JSON, for Console and fleets to install}
                            {--nightly-at= : When this fleet\'s nightly scan starts (HH:MM), as Console staggers it}
                            {--slots= : The host-wide scan slots every install shares}
                            {--slots-dir= : Where Console keeps scan slots; tests holds its slots as Postgres advisory locks, so it is only reported}';

    protected $description = 'List the launchd workers, the schedule and the host names this install needs on the Mac';

    public function handle(Runtime $runtime): int
    {
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
}
