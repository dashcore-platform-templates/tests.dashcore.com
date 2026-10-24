<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * The nightly launch — a whole client brought on across the practice fleet
 * (`tests:launch`) — on the builds queue, whose long-lived workers outlast
 * it. The schedule's own tick would not: launchd ends what a tick started,
 * and a launch that builds a fleet runs for many minutes.
 */
class FlyLaunch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public string $target = 'practice')
    {
        $this->timeout = (int) config("simulation.fleets.{$target}.provisioning_timeout", 3600) + 3600;
        $this->onConnection(config('scanner.queue.connection'));
        $this->onQueue('builds');
    }

    public function handle(): void
    {
        Artisan::call('tests:launch', ['--target' => $this->target]);
    }
}
