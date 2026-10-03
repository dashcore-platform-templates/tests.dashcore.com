<?php

namespace App\Simulation\Journeys\Sales;

/**
 * Not every deal closes: the same loop, ending in a loss.
 */
class CloseLostDeal extends CloseWonDeal
{
    public function key(): string
    {
        return 'sales.close-lost-deal';
    }

    public function name(): string
    {
        return 'Lead to lost deal';
    }

    protected function outcome(): string
    {
        return 'lost';
    }
}
