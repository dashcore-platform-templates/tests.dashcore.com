<?php

namespace App\Simulation\Journeys\Sales;

use App\Simulation\Journey;
use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * The whole sales loop: a new lead, a call logged, a deal opened from the
 * lead, and the deal marked won.
 */
class CloseWonDeal extends Journey
{
    public function key(): string
    {
        return 'sales.close-won-deal';
    }

    public function name(): string
    {
        return 'Lead to won deal';
    }

    public function target(): string
    {
        return 'sales';
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $who = $persona->firstName();
        $lead = $this->prospect($faker);
        $amount = $faker->numberBetween(20, 600) * 100;
        $call = 'Discovery call: '.$faker->sentence(8);
        $money = '$'.number_format($amount);

        return $this->signIn(new Script, $persona)
            ->visit('/leads', "{$who} opens the leads list")
            ->click('role=button[name="Add lead"]', "{$who} starts a new lead")
            ->fill('#newName', $lead['name'], "{$who} types the name {$lead['name']}")
            ->fill('#newEmail', $lead['email'], "{$who} types the email {$lead['email']}")
            ->fill('#newCompany', $lead['company'], "{$who} types the company {$lead['company']}")
            ->click('form[wire\:submit\.prevent="createLead"] button[type=submit]', "{$who} saves the lead")
            ->see('Lead added.', "{$who} sees the lead was added")
            ->fill('#leadSearch', $lead['email'], "{$who} searches for {$lead['name']}")
            ->click("a:has-text(\"{$lead['name']}\")", "{$who} opens {$lead['name']}")
            ->see($lead['email'], "{$who} is on {$lead['name']}'s page")
            ->click('role=button[name="Call"]', "{$who} picks Call to log a touch")
            ->fill('form[wire\:submit\.prevent="logActivity"] textarea', $call, "{$who} writes up the call")
            ->click('form[wire\:submit\.prevent="logActivity"] button[type=submit]', "{$who} logs the call")
            ->see($call, "{$who} sees the call in the timeline")
            ->click('role=button[name="Create deal"]', "{$who} opens a deal from the lead")
            ->fill('#convertAmount', (string) $amount, "{$who} prices it at {$money}")
            ->click('form[wire\:submit\.prevent="convertToDeal"] button[type=submit]', "{$who} creates the deal")
            ->see('Opportunity', "{$who} sees the new deal on the lead")
            ->click('role=button[name="Won" s]', "{$who} marks the deal won", confirm: true)
            ->gone('role=button[name="Won" s]', "{$who} sees the deal closed as won")
            ->summary("{$who} won a {$money} deal with {$lead['company']}");
    }
}
