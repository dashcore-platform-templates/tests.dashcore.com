<?php

namespace App\Simulation\Pulse;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

class FinancePulse implements Pulse
{
    public function read(ConnectionInterface $db, Carbon $now, ?Carbon $since): array
    {
        $month = $now->copy()->startOfMonth()->toDateString();
        $transactions = $db->table('transactions');
        $added = (clone $transactions)->where('created_at', '>=', $since ?? $now->copy()->startOfDay())->count();

        $totals = (clone $transactions)->where('occurred_on', '>=', $month)
            ->selectRaw("coalesce(sum(case when type = 'income' then amount end), 0) as income")
            ->selectRaw("coalesce(sum(case when type = 'expense' then amount end), 0) as expenses")
            ->first();

        $net = (float) $totals->income - (float) $totals->expenses;
        $recurring = $db->table('recurring_income_patterns')->where('is_active', true)->where('excluded_from_forecast', false)
            ->selectRaw('count(*) as payers, coalesce(sum(average_amount), 0) as amount')->first();

        return [
            [
                'label' => 'Transactions',
                'value' => number_format($transactions->count()),
                'hint' => '+'.number_format($added).($since ? ' since switched on' : ' today'),
            ],
            [
                'label' => 'Income this month',
                'value' => '$'.number_format((float) $totals->income),
                'hint' => 'by the date it came in',
            ],
            [
                'label' => 'Expenses this month',
                'value' => '$'.number_format((float) $totals->expenses),
                'hint' => 'by the date it went out',
            ],
            [
                'label' => 'Net this month',
                'value' => ($net < 0 ? '−$' : '$').number_format(abs($net)),
                'hint' => $net < 0 ? 'spending more than earning' : 'earning more than spending',
            ],
            [
                'label' => 'Recurring income',
                'value' => '$'.number_format((float) $recurring->amount),
                'hint' => number_format((int) $recurring->payers).' active '.str('payer')->plural((int) $recurring->payers),
            ],
        ];
    }
}
