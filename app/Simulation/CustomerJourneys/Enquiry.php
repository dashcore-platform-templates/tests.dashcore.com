<?php

namespace App\Simulation\CustomerJourneys;

use App\Simulation\CustomerJourney;
use App\Simulation\Script;
use Faker\Generator;
use Illuminate\Support\Str;

/**
 * Journey 1: a prospect fills in the website's contact form (/lets-talk),
 * and a rep finds them in sales as a lead.
 *
 * Between the two, the website posts the enquiry to sales over the bridge
 * (sales.capture) — the hop no single app's suite can see. The prospect's
 * address is new every run, on example.com, so a run never finds an earlier
 * run's lead and nothing it types can reach a real inbox.
 */
class Enquiry extends CustomerJourney
{
    public function name(): string
    {
        return 'A prospect enquires on the website and reaches sales';
    }

    public function signsIn(): array
    {
        return ['sales' => 'Sales rep'];
    }

    public function script(array $people, Generator $faker): Script
    {
        $first = $faker->firstName();
        $last = $faker->lastName();
        $email = 'journey+'.Str::lower(Str::random(12)).'@example.com';
        $rep = $people['sales'];
        $who = $rep->firstName();

        $script = (new Script)
            ->on('website')
            ->visit('/lets-talk', 'The prospect opens the contact page')
            ->fill('#df-first', $first, "They type their first name, {$first}")
            ->fill('#df-last', $last, "They type their last name, {$last}")
            ->fill('#df-email', $email, "They type their email, {$email}")
            // What sales must show is what the form held, read back off it.
            ->remember('#df-email', 'email', 'The form holds their email')
            ->fill('#df-company', "{$last} Workshop", "They type their company, {$last} Workshop")
            ->select('#df-size', '2–10', 'They pick a company size')
            // Plain words: the website withholds anything that reads as spam
            // from sales, and a withheld lead would fail the journey for the
            // wrong reason.
            ->fill('#df-need', 'Our quotes and follow-ups live in three spreadsheets and we keep losing track. (A simulated enquiry from tests.dashcore.com.)', 'They say what they need')
            // The honeypot (#df-website) is never touched: filled, the site
            // shows a fake thank-you and sends nothing on.
            ->filled('#df-turnstile input[name="cf-turnstile-response"]', 'Turnstile lets them through (the practice keys always pass)')
            ->click('#df-submit', 'They send the form')
            ->see('we got your details', 'The website thanks them')
            ->on('sales');

        return $this->signIn($script, $rep)
            ->visit('/leads', "{$who} opens the leads list")
            ->fill('#leadSearch', '{email}', "{$who} searches for {email}")
            ->see('{email}', "{$who} finds {email} in the list")
            ->click('tbody tr:first-child a:has-text("Open")', "{$who} opens the lead")
            ->see('{email}', "{$who} sees the lead is {email}")
            ->see("{$first} {$last}", "{$who} sees it is {$first} {$last}")
            ->summary("{$first} {$last} enquired on the website and {$who} found them in sales");
    }
}
