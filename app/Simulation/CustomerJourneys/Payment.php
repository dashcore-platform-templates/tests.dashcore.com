<?php

namespace App\Simulation\CustomerJourneys;

use App\Simulation\CustomerJourney;
use App\Simulation\Script;
use Faker\Generator;
use Illuminate\Support\Str;

/**
 * Journey 2: the prospect from Journey 1 is sent a payment link, pays it,
 * and sees what they bought in their portal — with no step done by hand.
 *
 * The path a customer takes today, from an enquiry: the rep makes a deal on
 * the lead in sales and presses "Send to DCOS & get payment link". That is
 * sales' DeliveryHandoff calling delivery (dcos) over the bridge
 * (delivery.crm.checkout): delivery makes a deal of its own at the agreed
 * price, moves it to Proposal Sent and hands back a signed /pay/{deal} link.
 * The rep sends it ("Send payment link") — an email, since the website's
 * form takes no phone — and the prospect opens it from that email, read
 * here from sales' mail log, where a stand-in's mail goes.
 *
 * /pay mints a Stripe Checkout session at click time, for a one-time
 * payment at the agreed price; the prospect pays it with Stripe's test card.
 * Stripe sends them to delivery's /portal/checkout/success, which makes
 * their portal account and sends them straight to set a password — the way
 * in a new client really takes (the kickoff email carries the same link, as
 * a backup). Meanwhile the checkout.session.completed webhook, carried by
 * the Stripe CLI, marks delivery's deal Won, which makes the project and
 * records the payment. The portal shows both once it has landed.
 *
 * Not the $300/month API + CEO plan: on v0.1.2 nothing in sales can make
 * one. Delivery files every CRM handoff as an Operating System deal, so an
 * API + CEO subscription starts only from a deal a person keys into
 * delivery's own deals page — a step done by hand.
 */
class Payment extends CustomerJourney
{
    /** What the rep sells, in dollars: what Stripe charges and the portal shows. */
    private const AMOUNT = 1250;

    private const TITLE = 'Operations setup';

    public function __construct(private Enquiry $enquiry) {}

    public function name(): string
    {
        return 'The prospect is sent a payment link, pays it, and sees it in their portal';
    }

    public function signsIn(): array
    {
        return $this->enquiry->signsIn();
    }

    public function stripeWebhookApp(): ?string
    {
        return 'delivery';
    }

    public function script(array $people, Generator $faker): Script
    {
        $prospect = Prospect::fake($faker);
        $rep = $people['sales'];
        $who = $rep->firstName();
        $amount = '$'.number_format(self::AMOUNT, 2);
        // For their portal, typed twice and never shown.
        $password = Str::password(20, symbols: false);

        return $this->enquiry->reachSales(new Script, $rep, $prospect)
            // ── Sales: a deal, handed to delivery for its pay link ──
            ->click('button:has-text("Create one")', "{$who} starts a deal for them")
            ->fill('#convertTitle', self::TITLE, "{$who} calls it ".self::TITLE)
            ->fill('#convertAmount', (string) self::AMOUNT, "{$who} prices it at {$amount}")
            ->click('form[wire\:submit\.prevent="convertToDeal"] button[type="submit"]', "{$who} makes the deal")
            ->see(self::TITLE, "{$who} sees the deal on the lead")
            ->click('button:has-text("Send to DCOS")', "{$who} sends it to delivery for a payment link", confirm: true)
            ->see('In delivery', 'Delivery took the deal and sent back a payment link')
            ->click('button:has-text("Send payment link")', "{$who} sends the payment link to {email}", confirm: true)
            // ── The prospect's inbox: sales' mail log ──
            ->mail('{email}', '\bhttps?://[^\s"\'<>]+/pay/\d+\?signature=[0-9a-f]+', 'pay_link', 'The payment link arrives in their email')
            // ── Delivery, and Stripe ──
            ->on('delivery')
            ->visit('{pay_link}', 'They open the payment link')
            ->payByCard([
                'number' => '4242424242424242',
                'expiry' => now()->addYears(3)->format('m / y'),
                'cvc' => '123',
                'name' => $prospect->name(),
                'email' => $prospect->email,
                'postal_code' => '94107',
            ], "They pay {$amount} with Stripe's test card")
            ->see('Set your password', 'Delivery sends them on to set up their portal account')
            ->fill('#password', $password, 'They choose a password')
            ->fill('#password_confirmation', $password, 'They type it again')
            ->click('form button[type="submit"]', 'They set it')
            // The project exists once the webhook has won the deal; until
            // then the dashboard has nothing to show.
            ->seeWithin("{$prospect->company} — ".self::TITLE, 60, 'Their portal shows the project they paid for')
            ->visit('/portal/invoices', 'They open their invoices')
            ->see($amount, "They see the {$amount} payment")
            ->summary("{$prospect->name()} was sent a payment link by {$who}, paid {$amount} by card, and found {$prospect->company} — ".self::TITLE.' in their portal');
    }
}
