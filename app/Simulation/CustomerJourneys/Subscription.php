<?php

namespace App\Simulation\CustomerJourneys;

use App\Simulation\CustomerJourney;
use App\Simulation\Script;
use Faker\Generator;
use Illuminate\Support\Str;

/**
 * The prospect from Journey 1 is sold the $300/month API + CEO plan, is sent
 * a payment link, subscribes through it, and sees the plan in their portal —
 * with no step done by hand. Masterplan item 6's acceptance test, word for
 * word.
 *
 * The same road as Payment, with a plan where the price was. The rep makes
 * the deal on the lead and picks "API + CEO plan — $300/mo" from the deal's
 * "What is it?" list rather than typing an amount. "Send to DCOS & get
 * payment link" carries it to delivery as `deal.plan` = api_ceo over
 * delivery.crm.checkout; delivery files an API + CEO deal, and its signed
 * /pay/{deal} link mints a Stripe *subscription* checkout for $300 a month
 * instead of a one-time charge. The prospect subscribes with Stripe's test
 * card, sets their portal password, and the checkout.session.completed
 * webhook (carried by the Stripe CLI) wins the deal and records the plan —
 * with its first billing period, read from the subscription itself — so the
 * portal shows it active, at $300.00, billed monthly, with its next charge.
 *
 * It needs sales and dcos on the release that sells plans through the
 * hand-off, and the plan switched on in both: the practice fleet names a
 * company, which turns API + CEO off by default (it is Dashcore's own offer),
 * so both need DASHCORE_API_CEO_ENABLED=true. requires() checks that before
 * anything runs.
 */
class Subscription extends CustomerJourney
{
    /** The plan's monthly price, in dollars: what Stripe bills and the portal shows. */
    private const MONTHLY = 300;

    private const PLAN = 'api_ceo';

    private const TITLE = 'API + CEO';

    /**
     * What clients are shown the API + CEO plan as — on the lead, in the
     * portal and on the Stripe subscription dcos makes ("Dashcore Foundation
     * — Acme Care") — since sales' DAS-2227 and dcos' DAS-2197.
     */
    public const string SOLD_AS = 'Dashcore Foundation';

    public function __construct(private Enquiry $enquiry) {}

    public function name(): string
    {
        return 'The prospect is sold the API + CEO plan, subscribes from the payment link, and sees it in their portal';
    }

    public function signsIn(): array
    {
        return $this->enquiry->signsIn();
    }

    public function stripeWebhookApp(): ?string
    {
        return 'delivery';
    }

    public function requires(): array
    {
        return [
            'sales' => ['dashcore.plans.api_ceo.enabled' => true],
            'delivery' => ['dashcore.api_ceo.enabled' => true],
        ];
    }

    public function script(array $people, Generator $faker): Script
    {
        $prospect = Prospect::fake($faker);
        $rep = $people['sales'];
        $who = $rep->firstName();
        $monthly = '$'.number_format(self::MONTHLY, 2);
        // For their portal, typed twice and never shown.
        $password = Str::password(20, symbols: false);
        // Stripe bills a month on from today, clamped to a short month's end
        // the way Stripe clamps it; the portal prints it on the company's
        // clock, which this app (still on UTC) does not share — after 5 pm
        // Pacific the two disagree on what "today" is.
        $nextCharge = now(config('simulation.company_clock'))->addMonthNoOverflow()->format('M j, Y');

        return $this->enquiry->reachSales(new Script, $rep, $prospect)
            // ── Sales: a plan deal, handed to delivery for its pay link ──
            ->click('button:has-text("Create one")', "{$who} starts a deal for them")
            ->fill('#convertTitle', self::TITLE, "{$who} calls it ".self::TITLE)
            ->select('#convertPlan', self::PLAN, "{$who} picks the API + CEO plan — \$".self::MONTHLY.'/mo')
            ->click('form[wire\:submit\.prevent="convertToDeal"] button[type="submit"]', "{$who} makes the deal")
            ->see(self::SOLD_AS.' plan', "{$who} sees the plan on the lead")
            ->see('$'.self::MONTHLY.'/mo', "{$who} sees it priced at \$".self::MONTHLY.' a month')
            ->click('button:has-text("Send to DCOS")', "{$who} sends it to delivery for a payment link", confirm: true)
            ->see('In delivery', 'Delivery took the plan and sent back a payment link')
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
            ], "They subscribe at {$monthly} a month with Stripe's test card")
            ->see('Set your password', 'Delivery sends them on to set up their portal account')
            ->fill('#password', $password, 'They choose a password')
            ->fill('#password_confirmation', $password, 'They type it again')
            ->click('form button[type="submit"]', 'They set it')
            // The plan is recorded once the webhook has won the deal; until
            // then the dashboard has nothing to show.
            ->seeWithin('Your '.self::SOLD_AS.' plan is active', 60, 'Their portal shows the '.self::SOLD_AS.' plan, active')
            ->see($monthly, "at {$monthly}")
            ->see('Billed monthly', 'billed monthly')
            ->see($nextCharge, "with its next charge on {$nextCharge}")
            ->summary("{$prospect->name()} was sold the API + CEO plan by {$who}, subscribed at {$monthly} a month by card, and found it active in {$prospect->company}'s portal");
    }
}
