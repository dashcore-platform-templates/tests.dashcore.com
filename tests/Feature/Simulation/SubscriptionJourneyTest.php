<?php

namespace Tests\Feature\Simulation;

use App\Simulation\CustomerJourneys\Enquiry;
use App\Simulation\CustomerJourneys\Payment;
use App\Simulation\CustomerJourneys\Prospect;
use App\Simulation\CustomerJourneys\Subscription;
use App\Simulation\Persona;
use App\Simulation\Script;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SubscriptionJourneyTest extends TestCase
{
    private Persona $rep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rep = new Persona('Journey Rep', 'journey.rep@example.com', 'rep-password', 'Sales rep');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function steps(string $journey = Subscription::class): Collection
    {
        return collect(app($journey)->script(['sales' => $this->rep], fake())->steps());
    }

    public function test_it_pays_through_delivery_and_signs_in_only_to_sales(): void
    {
        $journey = app(Subscription::class);

        $this->assertSame('delivery', $journey->stripeWebhookApp());
        $this->assertSame(['sales' => 'Sales rep'], $journey->signsIn());
    }

    public function test_it_needs_the_plan_switched_on_in_both_apps_it_crosses(): void
    {
        $this->assertSame([
            'sales' => ['dashcore.plans.api_ceo.enabled' => true],
            'delivery' => ['dashcore.api_ceo.enabled' => true],
        ], app(Subscription::class)->requires());

        // The one-time journey asks nothing of the stand-in beyond the guard.
        $this->assertSame([], app(Payment::class)->requires());
        $this->assertSame([], app(Enquiry::class)->requires());
    }

    public function test_it_walks_the_same_road_as_the_one_time_payment_with_a_plan_where_the_price_was(): void
    {
        $shape = fn (array $step) => [$step['do'], $step['on'] ?? null];

        $subscription = $this->steps();
        $payment = $this->steps(Payment::class);
        $enquiry = count(app(Enquiry::class)->reachSales(new Script, $this->rep, new Prospect('A', 'B', 'journey+x@example.com', 'B Workshop'))->steps());

        // Enquiry first, exactly as Journey 1 walks it.
        $this->assertSame($payment->take($enquiry)->map($shape)->all(), $subscription->take($enquiry)->map($shape)->all());

        $sales = $subscription->where('on', 'sales')->values();

        // The plan is picked from the deal's list, never typed as an amount.
        $this->assertNull($sales->firstWhere('target', '#convertAmount'));
        $this->assertSame(['do' => 'select', 'value' => 'api_ceo'], collect($sales->firstWhere('target', '#convertPlan'))->only('do', 'value')->all());

        $handoff = $sales->search(fn ($step) => ($step['target'] ?? null) === 'button:has-text("Send to DCOS")');
        $send = $sales->search(fn ($step) => ($step['target'] ?? null) === 'button:has-text("Send payment link")');
        $this->assertTrue($sales[$handoff]['confirm']);
        $this->assertTrue($sales[$send]['confirm']);
        $this->assertLessThan($send, $handoff);
        $this->assertNotNull($sales->firstWhere('text', '$300/mo'));
    }

    public function test_it_subscribes_from_the_link_in_the_email_with_stripes_test_card(): void
    {
        Carbon::setTestNow('2026-10-06 12:00:00');

        $steps = $this->steps();
        $opened = $steps->where('on', 'delivery')->first();
        $pay = $steps->firstWhere('do', 'stripe_checkout');

        $this->assertSame(['visit', '{pay_link}'], [$opened['do'], $opened['path']]);
        $this->assertSame('pay_link', $steps->firstWhere('do', 'mail')['as']);
        $this->assertSame('4242424242424242', $pay['number']);
        $this->assertSame('10 / 29', $pay['expiry']);
        $this->assertStringContainsString('$300.00 a month', $pay['label']);
    }

    public function test_the_portal_shows_the_plan_active_at_its_price_with_its_next_charge(): void
    {
        Carbon::setTestNow('2026-10-06 12:00:00');

        $steps = $this->steps();
        $portal = $steps->skipUntil(fn (array $step) => isset($step['reload_for_ms']))->values();

        // Waited for: the webhook records the plan after the page has loaded.
        $this->assertSame('Your API + CEO plan is active', $portal[0]['text']);
        $this->assertSame(60000, $portal[0]['reload_for_ms']);
        $this->assertSame(['$300.00', 'Billed monthly', 'Nov 6, 2026'], $portal->slice(1)->pluck('text')->values()->all());
    }

    public function test_the_next_charge_is_clamped_to_a_short_months_end_as_stripe_bills_it(): void
    {
        Carbon::setTestNow('2027-01-31 12:00:00');

        $this->assertSame('Feb 28, 2027', $this->steps()->last()['text']);
    }

    public function test_the_new_clients_password_is_never_shown(): void
    {
        $steps = $this->steps();
        $password = $steps->firstWhere('target', '#password_confirmation')['value'];

        $this->assertSame($password, $steps->where('on', 'delivery')->firstWhere('target', '#password')['value']);
        $this->assertNotSame('rep-password', $password);
        $steps->each(fn (array $step) => $this->assertStringNotContainsString($password, $step['label']));
    }
}
