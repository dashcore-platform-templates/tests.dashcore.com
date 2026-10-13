<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Console;

use Dashcore\Bridge\Mail\MailReporter;
use Illuminate\Console\Command;

/**
 * Send this app's mail attempts to the collector. Scheduled by the package
 * itself every two minutes, so no app has to add it.
 */
class ReportMailCommand extends Command
{
    protected $signature = 'bridge:report-mail
                            {--limit= : Maximum attempts to send in one run}
                            {--prune : Also delete reported attempts past the retention window}';

    protected $description = 'Report the emails this application tried to send to the fleet collector';

    public function handle(MailReporter $reporter): int
    {
        $result = $reporter->report(
            $this->option('limit') !== null ? (int) $this->option('limit') : MailReporter::BATCH,
        );

        if ($result['skipped'] !== null) {
            $this->components->warn($result['skipped']);
        } else {
            $this->components->info($result['sent'] > 0 ? "Reported {$result['sent']} email(s)." : 'Nothing new to report.');
        }

        if ($this->option('prune')) {
            $this->components->info("Pruned {$reporter->prune()} reported attempt(s).");
        }

        return self::SUCCESS;
    }
}
