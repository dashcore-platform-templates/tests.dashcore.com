<?php

namespace App\Jobs;

use App\Building\Builder;
use App\Building\TestkitInstaller;
use App\Enums\BuildKind;
use App\Enums\BuildStatus;
use App\Models\Build;
use App\Scanning\ScanSlots;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one build on the long-running connection, after any scans waiting.
 * One attempt only, for the same reason as RunScan.
 */
class RunBuild implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public Build $build)
    {
        $this->timeout = (int) config('scanner.build.timeout') + (int) config('scanner.timeout') * 2 + 1800;
        $this->onConnection(config('scanner.queue.connection'));
        $this->onQueue('builds');
    }

    public function handle(Builder $builder, TestkitInstaller $installer, ?ScanSlots $slots = null): void
    {
        $slots ??= app(ScanSlots::class);

        if ($this->build->status !== BuildStatus::Queued) {
            return;
        }

        // A build runs suites too, so it takes a scan slot like a scan does.
        if ($slots->acquire() === null) {
            self::dispatch($this->build)->delay(now()->addSeconds((int) config('scanner.slots.retry_after')));

            return;
        }

        try {
            $this->build->kind === BuildKind::Testkit
                ? $installer->run($this->build)
                : $builder->run($this->build);
        } finally {
            $slots->release();
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->build->refresh();

        if ($this->build->status->isActive()) {
            $this->build->update([
                'status' => BuildStatus::Failed,
                'error' => 'The build worker stopped before the build finished'.($e ? ': '.$e->getMessage() : '.'),
                'finished_at' => now(),
            ]);
        }
    }
}
