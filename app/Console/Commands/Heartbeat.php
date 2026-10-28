<?php

namespace App\Console\Commands;

use App\Services\ScheduleHealth;
use App\Workflows\Heartbeat as Beat;
use Illuminate\Console\Command;
use Throwable;

/**
 * Every scheduled tick, says this install's schedule is alive — scans and
 * workflows alike — to the page and to the Healthchecks.io-style check off
 * this machine, which texts when the pings stop or arrive at /fail.
 */
class Heartbeat extends Command
{
    protected $signature = 'tests:heartbeat';

    protected $description = 'Ping this install\'s heartbeat: the schedule ticked, and its scans and workflows machinery works';

    public function handle(ScheduleHealth $health, Beat $beat): int
    {
        try {
            $state = $health->check();
        } catch (Throwable $e) {
            report($e);
            $state = ['healthy' => false, 'note' => 'tests:heartbeat could not check the schedule: '.$e->getMessage()];
        }

        $beat->beat($state['healthy'], $state['note']);
        $this->line($state['note']);

        return self::SUCCESS;
    }
}
