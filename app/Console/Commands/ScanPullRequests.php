<?php

namespace App\Console\Commands;

use App\Scanning\PullRequestScans;
use App\Services\Install;
use Illuminate\Console\Command;

class ScanPullRequests extends Command
{
    protected $signature = 'tests:scan-prs';

    protected $description = 'Queue a scan of every new open pull request head in the fleet and report each as a commit status';

    public function handle(PullRequestScans $scans): int
    {
        if (! Install::isHome()) {
            $this->components->info('Only Dashcore\'s own tests scans pull requests; this install is the '.Install::fleet().' fleet\'s.');

            return self::SUCCESS;
        }

        foreach ($scans->discover() as $key => $result) {
            $this->components->twoColumnDetail($key, match (true) {
                $result['skipped'] !== null => '<fg=gray>'.$result['skipped'].'</>',
                $result['queued'] === [] => '<fg=gray>nothing new</>',
                default => '<fg=green>queued</> #'.implode(', #', $result['queued']),
            });
        }

        return self::SUCCESS;
    }
}
