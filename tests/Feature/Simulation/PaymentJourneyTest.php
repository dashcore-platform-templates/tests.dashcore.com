<?php

namespace Tests\Feature\Simulation;

use App\Simulation\CustomerJourneys\Enquiry;
use App\Simulation\CustomerJourneys\Payment;
use App\Simulation\CustomerJourneys\Prospect;
use App\Simulation\Persona;
use App\Simulation\Script;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PaymentJourneyTest extends TestCase
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
    private function steps(): Collection
    {
        return collect(app(Payment::class)->script(['sales' => $this->rep], fake())->steps());
    }

    public function test_it_pays_through_delivery_and_signs_in_only_to_sales(): void
    {
        $journey = app(Payment::class);

        $this->assertSame('delivery', $journey->stripeWebhookApp());
        $this->assertSame(['sales' => 'Sales rep'], $journey->signsIn());
        $this->assertNull(app(Enquiry::class)->stripeWebhookApp());
    }

    public function test_it_starts_by_walking_the_enquiry_itself(): void
    {
        // The lead is made through the website's form, as Journey 1 makes it,
        // never some quicker way that skips the hop into sales.
        $enquiry = app(Enquiry::class)->reachSales(new Script, $this->rep, new Prospect('A', 'B', 'journey+x@example.com', 'B Workshop'))->steps();
        $payment = $this->steps();

        $shape = fn (array $step) => [$step['do'], $step['on'] ?? null, $step['target'] ?? $step['path'] ?? null];

        $this->assertSame(array_map($shape, $enquiry), $payment->take(count($enquiry))->map($shape)->all());
        $this->assertSame('sales', $payment[count($enquiry)]['on']);
    }

    public function test_the_rep_hands_the_deal_to_delivery_and_sends_the_link(): void
    {
        $sales = $this->steps()->where('on', 'sales')->values();

        $handoff = $sales->firstWhere('target', 'button:has-text("Send to DCOS")');
        $send = $sales->firstWhere('target', 'button:has-text("Send payment link")');

        // Both are wire:confirm buttons: the dialog must be accepted.
        $this->assertTrue($handoff['confirm']);
        $this->assertTrue($send['confirm']);
        $this->assertSame('1250', $sales->firstWhere('target', '#convertAmount')['value']);
        $this->assertLessThan($sales->search($send), $sales->search($handoff));
    }

    public function test_the_payment_link_is_read_from_the_email_sales_sent(): void
    {
        $mail = $this->steps()->firstWhere('do', 'mail');

        $this->assertSame('sales', $mail['on']);
        $this->assertSame('{email}', $mail['to']);
        $this->assertSame('pay_link', $mail['as']);

        // The pattern finds delivery's signed /pay link in the message, and
        // stops where the link does.
        $pattern = '~'.str_replace('~', '\~', $mail['pattern']).'~';
        $body = "Hi Dana,\r\n\r\nHere's your payment link:\r\nhttp://dcos.travischapman.practice.test/pay/42?signature=0f3a9c<br>\r\n<a href=\"http://dcos.travischapman.practice.test/pay/42?signature=0f3a9c\">";
        $this->assertSame(1, preg_match($pattern, $body, $match));
        $this->assertSame('http://dcos.travischapman.practice.test/pay/42?signature=0f3a9c', $match[0]);
        $this->assertSame(0, preg_match($pattern, 'http://dcos.travischapman.practice.test/portal/login'));

        // And it is that link, from the email, that the prospect opens.
        $opened = $this->steps()->where('on', 'delivery')->first();
        $this->assertSame(['visit', '{pay_link}'], [$opened['do'], $opened['path']]);
    }

    public function test_it_pays_with_stripes_test_card_and_a_card_that_has_not_expired(): void
    {
        Carbon::setTestNow('2026-10-06 12:00:00');

        $pay = $this->steps()->firstWhere('do', 'stripe_checkout');

        $this->assertSame('4242424242424242', $pay['number']);
        $this->assertSame('10 / 29', $pay['expiry']);
        $this->assertSame('checkout.stripe.com', $pay['host']);
        $this->assertMatchesRegularExpression('/^journey\+[a-z0-9]{12}@example\.com$/', $pay['email']);
        $this->assertSame('delivery', $pay['on']);
    }

    public function test_the_portal_is_checked_after_the_webhook_has_had_time_to_land(): void
    {
        $steps = $this->steps();
        $password = $steps->firstWhere('target', '#password_confirmation')['value'];

        // The new client's own password, typed twice, never shown in a label.
        $this->assertSame($password, $steps->where('on', 'delivery')->firstWhere('target', '#password')['value']);
        $this->assertNotSame('rep-password', $password);
        $steps->each(fn (array $step) => $this->assertStringNotContainsString($password, $step['label']));

        $project = $steps->first(fn (array $step) => isset($step['reload_for_ms']));
        $this->assertSame(60000, $project['reload_for_ms']);
        $this->assertStringEndsWith('Workshop — Operations setup', $project['text']);
        $this->assertSame('$1,250.00', $steps->last()['text']);
    }
}
