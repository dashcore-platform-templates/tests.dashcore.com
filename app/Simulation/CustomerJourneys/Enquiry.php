<?php

namespace App\Simulation\CustomerJourneys;

use App\Simulation\CustomerJourney;
use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * Journey 1: a prospect fills in the website's contact form (/lets-talk),
 * and a rep finds them in sales as a lead.
 *
 * Between the two, the website posts the enquiry to sales over the bridge
 * (sales.capture) — the hop no single app's suite can see. The prospect's
 * address is new every run, on example.com, so a run never finds an earlier
 * run's lead and nothing it types can reach a real inbox.
 *
 * Later stages start where this one ends — a rep on the new lead's page —
 * so they walk it first, through reachSales(), rather than make the lead
 * some quicker way that would skip the hop they depend on.
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
        $prospect = Prospect::fake($faker);

        return $this->reachSales(new Script, $people['sales'], $prospect)
            ->summary("{$prospect->name()} enquired on the website and {$people['sales']->firstName()} found them in sales");
    }

    /**
     * The prospect enquires on the website, and the rep signs in to sales
     * and opens the lead it made. Ends on the lead's page, in sales.
     */
    public function reachSales(Script $script, Persona $rep, Prospect $prospect): Script
    {
        $who = $rep->firstName();

        $script
            ->on('website')
            ->visit('/lets-talk', 'The prospect opens the contact page')
            ->fill('#df-first', $prospect->first, "They type their first name, {$prospect->first}")
            ->fill('#df-last', $prospect->last, "They type their last name, {$prospect->last}")
            ->fill('#df-email', $prospect->email, "They type their email, {$prospect->email}")
            // What sales must show is what the form held, read back off it.
            ->remember('#df-email', 'email', 'The form holds their email')
            ->fill('#df-company', $prospect->company, "They type their company, {$prospect->company}")
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
            ->see($prospect->name(), "{$who} sees it is {$prospect->name()}");
    }
}
