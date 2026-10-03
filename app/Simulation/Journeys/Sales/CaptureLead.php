<?php

namespace App\Simulation\Journeys\Sales;

use App\Simulation\Journey;
use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * A rep adds a lead by hand and sees it arrive at the top of the list.
 */
class CaptureLead extends Journey
{
    public function key(): string
    {
        return 'sales.capture-lead';
    }

    public function name(): string
    {
        return 'Capture a lead';
    }

    public function target(): string
    {
        return 'sales';
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $who = $persona->firstName();
        $lead = $this->prospect($faker);

        return $this->signIn(new Script, $persona)
            ->visit('/leads', "{$who} opens the leads list")
            ->click('role=button[name="Add lead"]', "{$who} starts a new lead")
            ->fill('#newName', $lead['name'], "{$who} types the name {$lead['name']}")
            ->fill('#newEmail', $lead['email'], "{$who} types the email {$lead['email']}")
            ->fill('#newPhone', $lead['phone'], "{$who} types the phone {$lead['phone']}")
            ->fill('#newCompany', $lead['company'], "{$who} types the company {$lead['company']}")
            ->fill('#newWebsite', $lead['website'], "{$who} types the website {$lead['website']}")
            ->fill('#newMessage', $faker->sentence(12), "{$who} notes what they asked for")
            ->click('form[wire\:submit\.prevent="createLead"] button[type=submit]', "{$who} saves the lead")
            ->see('Lead added.', "{$who} sees the lead was added")
            ->see($lead['name'], "{$who} sees {$lead['name']} in the list")
            ->summary("{$who} added lead {$lead['name']} from {$lead['company']}");
    }
}
