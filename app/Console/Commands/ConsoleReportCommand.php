<?php

namespace App\Console\Commands;

use App\Reporting\ConsoleReport;
use Illuminate\Console\Command;

class ConsoleReportCommand extends Command
{
    protected $signature = 'console:report
                            {--json : Print Console\'s report contract as JSON (the only format, kept explicit for callers)}';

    protected $description = 'Report this install\'s failed scans, regressions, journeys and heartbeat to Console';

    public function handle(ConsoleReport $report): int
    {
        $this->line((string) json_encode($report->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
