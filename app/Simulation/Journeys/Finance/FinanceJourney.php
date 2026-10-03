<?php

namespace App\Simulation\Journeys\Finance;

use App\Simulation\Journey;
use App\Simulation\Script;

/**
 * Work in the fake company's finance app (finance.fakecompany.com): a ledger
 * over a QuickBooks sync, whose forms are Flux modals that confirm with a
 * toast. Nothing here connects to QuickBooks, emails a report or asks the AI.
 */
abstract class FinanceJourney extends Journey
{
    public function target(): string
    {
        return 'finance';
    }

    public function roles(): array
    {
        return ['Bookkeeper', 'Finance lead'];
    }

    /**
     * Add a transaction by hand from the transactions page.
     */
    protected function addTransaction(Script $script, string $who, string $type, string $amount, string $date, string $description): Script
    {
        return $script
            ->visit('/transactions', "{$who} opens the transactions")
            ->click('button[wire\:click="createTransaction"]', "{$who} starts a new transaction")
            ->select('select[wire\:model\.live="type"]', $type, "{$who} marks it as {$type}")
            ->fill('input[wire\:model="amount"]', $amount, "{$who} enters $".number_format((float) $amount, 2))
            ->fill('input[wire\:model="occurred_on"]', $date, "{$who} dates it {$date}")
            ->fill('textarea[wire\:model\.live\.debounce\.400ms="description"]', $description, "{$who} describes it: {$description}")
            ->click('form[wire\:submit="save"] button[type=submit]', "{$who} saves the transaction")
            ->see('Transaction added.', "{$who} sees it was added");
    }

    /**
     * Look a transaction up by its description, as a bookkeeper checking
     * their entry would — the list is newest first, so a backdated one is
     * not on the first page.
     */
    protected function findTransaction(Script $script, string $who, string $description): Script
    {
        return $script
            ->fill('input[wire\:model\.live\.debounce\.400ms="search"]', $description, "{$who} searches for it")
            ->see($description, "{$who} finds it in the list");
    }
}
