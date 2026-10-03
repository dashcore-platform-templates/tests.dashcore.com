<?php

namespace App\Simulation\Pulse;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * The fake company's vital signs in one app, read straight from its
 * database. Read only: the simulation changes an app through its UI, never
 * behind its back.
 */
interface Pulse
{
    /**
     * Both times are in the app's own timezone, the one its timestamps are
     * stored in, so "today" means the app's today.
     *
     * @param  Carbon  $now  the current time
     * @param  Carbon|null  $since  when the simulation was switched on, for "+N since"
     * @return list<array{label: string, value: string, hint: ?string}>
     */
    public function read(ConnectionInterface $db, Carbon $now, ?Carbon $since): array;
}
