<?php

namespace Dashcore\Bridge\Console;

use Dashcore\Bridge\Sso\SsoStatus;
use Illuminate\Console\Command;

/**
 * This app's sso posture, for a person or for whatever rolls sso out across
 * the fleet (fleets runs it with --json through the app's hosting and reads
 * the one line back). Read-only: it reports and never changes anything.
 */
class SsoStatusCommand extends Command
{
    protected $signature = 'bridge:sso {--json : One line of JSON, for a machine to read}';

    protected $description = 'Report how this app signs people in through the fleet: mode, break-glass, subject column, resolver';

    public function handle(): int
    {
        $status = SsoStatus::report();

        if ($this->option('json')) {
            $this->line((string) json_encode($status, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $yesNo = fn (?bool $v) => $v === null ? 'unknown' : ($v ? 'yes' : 'no');

        $this->components->twoColumnDetail('Bridge', $status['bridge']);
        $this->components->twoColumnDetail('Mode', $status['mode'].($status['mode'] !== $status['configured'] ? " (configured {$status['configured']}, no break-glass account)" : ''));
        $this->components->twoColumnDetail('Break-glass accounts', (string) $status['break_glass']);
        $this->components->twoColumnDetail('sso_subject column', $yesNo($status['subject_column']));
        $this->components->twoColumnDetail('Resolver', $status['resolver'].($status['app_resolver'] ? '' : ' (bridge default)'));
        $this->components->twoColumnDetail('Creates accounts', $yesNo($status['provision']));
        $this->components->twoColumnDetail('Failures land on', $status['login_route'] ?? '/ (no login route)');

        return self::SUCCESS;
    }
}
