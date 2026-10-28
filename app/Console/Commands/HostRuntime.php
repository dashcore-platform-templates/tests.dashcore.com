<?php

namespace App\Console\Commands;

use App\Scanning\HostRuntime as Runtime;
use Illuminate\Console\Command;

class HostRuntime extends Command
{
    protected $signature = 'tests:host-runtime
                            {--json : Print it as JSON, for fleets to install}';

    protected $description = 'List the launchd workers, the schedule and the host names this install needs on the Mac';

    public function handle(Runtime $runtime): int
    {
        $described = $runtime->describe();

        if ($this->option('json')) {
            $this->line((string) json_encode($described, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $install = $described['install'];
        $this->components->info("tests for the {$install['fleet']} fleet ({$install['host']})".($install['home'] ? ', the home install' : ''));

        foreach ($described['workers'] as $worker) {
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
