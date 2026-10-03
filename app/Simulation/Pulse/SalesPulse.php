<?php

namespace App\Simulation\Pulse;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

class SalesPulse implements Pulse
{
    public function read(ConnectionInterface $db, Carbon $now, ?Carbon $since): array
    {
        $today = $now->copy()->startOfDay();
        $week = $now->copy()->startOfWeek();
        $closed = ['won', 'lost'];

        $leads = $db->table('leads');
        $newLeads = $since ? (clone $leads)->where('created_at', '>=', $since)->count() : (clone $leads)->where('created_at', '>=', $today)->count();

        $open = $db->table('deals')->whereNotIn('stage', $closed)
            ->selectRaw('count(*) as deals, coalesce(sum(amount), 0) as value')->first();

        $won = $db->table('deals')->where('stage', 'won')->where('closed_at', '>=', $week)
            ->selectRaw('count(*) as deals, coalesce(sum(amount), 0) as value')->first();

        $lost = $db->table('deals')->where('stage', 'lost')->where('closed_at', '>=', $week)->count();

        $touched = $db->table('lead_activities')->whereNotNull('user_id')->where('occurred_at', '>=', $today)
            ->distinct()->count('lead_id');

        $followUps = $db->table('lead_tasks')->whereNull('completed_at');
        $overdue = (clone $followUps)->where('due_at', '<', $now)->count();

        return [
            [
                'label' => 'Leads',
                'value' => number_format($leads->count()),
                'hint' => '+'.number_format($newLeads).($since ? ' since switched on' : ' today'),
            ],
            [
                'label' => 'Touched today',
                'value' => number_format($touched),
                'hint' => 'leads with a call, email, meeting or note',
            ],
            [
                'label' => 'Open pipeline',
                'value' => '$'.number_format((float) $open->value),
                'hint' => number_format($open->deals).' open '.str('deal')->plural((int) $open->deals),
            ],
            [
                'label' => 'Won this week',
                'value' => '$'.number_format((float) $won->value),
                'hint' => number_format($won->deals).' won · '.number_format($lost).' lost',
            ],
            [
                'label' => 'Follow-ups',
                'value' => number_format($followUps->count()),
                'hint' => number_format($overdue).' overdue',
            ],
        ];
    }
}
