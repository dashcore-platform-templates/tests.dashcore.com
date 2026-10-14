<?php

namespace Tests\Feature\Scanning;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class NightlyScheduleTest extends TestCase
{
    private function nightlyScan(): Event
    {
        return collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_ends_with((string) $event->command, "'artisan' tests:scan"));
    }

    public function test_the_nightly_scan_runs_by_default(): void
    {
        config(['scanner.nightly' => true]);

        $this->assertSame('0 1 * * *', $this->nightlyScan()->expression);
        $this->assertTrue($this->nightlyScan()->filtersPass($this->app));
    }

    public function test_a_host_with_paused_scan_workers_can_switch_it_off(): void
    {
        config(['scanner.nightly' => false]);

        $this->assertFalse($this->nightlyScan()->filtersPass($this->app));
    }
}
