<?php

namespace App\Simulation\Journeys\Sales;

use App\Simulation\Journey;
use App\Simulation\Script;

/**
 * Work in the fake company's sales app (sales.fakecompany.com), and the
 * moves several journeys share.
 */
abstract class SalesJourney extends Journey
{
    public const ACTIVITY_FORM = 'form[wire\:submit\.prevent="logActivity"]';

    public function target(): string
    {
        return 'sales';
    }

    /**
     * Add a lead by hand from the leads list.
     *
     * @param  array{name: string, email: string, phone: string, company: string, website: string}  $lead
     */
    protected function addLead(Script $script, string $who, array $lead, bool $everything = false): Script
    {
        $script
            ->visit('/leads', "{$who} opens the leads list")
            ->click('role=button[name="Add lead"]', "{$who} starts a new lead")
            ->fill('#newName', $lead['name'], "{$who} types the name {$lead['name']}")
            ->fill('#newEmail', $lead['email'], "{$who} types the email {$lead['email']}");

        if ($everything) {
            $script->fill('#newPhone', $lead['phone'], "{$who} types the phone {$lead['phone']}");
        }

        $script->fill('#newCompany', $lead['company'], "{$who} types the company {$lead['company']}");

        if ($everything) {
            $script->fill('#newWebsite', $lead['website'], "{$who} types the website {$lead['website']}");
        }

        return $script;
    }

    protected function saveLead(Script $script, string $who): Script
    {
        return $script
            ->click('form[wire\:submit\.prevent="createLead"] button[type=submit]', "{$who} saves the lead")
            ->see('Lead added.', "{$who} sees the lead was added");
    }

    /**
     * Find a lead just added, by its unique email, and open it.
     *
     * @param  array{name: string, email: string}  $lead
     */
    protected function openLead(Script $script, string $who, array $lead): Script
    {
        return $script
            ->fill('#leadSearch', $lead['email'], "{$who} searches for {$lead['name']}")
            ->click("a:has-text(\"{$lead['name']}\")", "{$who} opens {$lead['name']}")
            ->see($lead['email'], "{$who} is on {$lead['name']}'s page");
    }

    /**
     * Log a touch on the open lead: Call, Text, Email, Meeting or Note.
     */
    protected function logActivity(Script $script, string $who, string $type, string $description): Script
    {
        $type = ucfirst($type);

        return $script
            ->click("div[x-data*=\"activityType\"] button:has-text(\"{$type}\")", "{$who} picks {$type} to log a touch")
            ->fill(self::ACTIVITY_FORM.' textarea', $description, "{$who} writes up the ".strtolower($type))
            ->click(self::ACTIVITY_FORM.' button[type=submit]', "{$who} logs the ".strtolower($type))
            ->see($description, "{$who} sees the ".strtolower($type).' in the timeline');
    }

    /**
     * Turn the open lead into a deal at the given amount.
     */
    protected function createDeal(Script $script, string $who, int $amount): Script
    {
        $money = '$'.number_format($amount);

        return $script
            ->click('role=button[name="Create deal"]', "{$who} opens a deal from the lead")
            ->fill('#convertAmount', (string) $amount, "{$who} prices it at {$money}")
            ->click('form[wire\:submit\.prevent="convertToDeal"] button[type=submit]', "{$who} creates the deal")
            ->see('Opportunity', "{$who} sees the new deal on the lead");
    }
}
