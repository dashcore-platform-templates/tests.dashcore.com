<?php

namespace Tests\Feature\Simulation;

use App\Simulation\StripeForwarder;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class StripeForwarderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['simulation.stripe.cli' => '/opt/stripe', 'simulation.stripe.events' => ['checkout.session.completed'], 'simulation.stripe.ready_timeout' => 2]);
    }

    public function test_a_signed_in_cli_gives_its_signing_secret(): void
    {
        Process::fake(['*' => Process::result("whsec_abc123\n")]);
        putenv('STRIPE_API_KEY=sk_live_from_this_apps_shell');

        try {
            $this->assertSame('whsec_abc123', app(StripeForwarder::class)->signingSecret());
        } finally {
            putenv('STRIPE_API_KEY');
        }

        // A key exported into this shell would beat the CLI's own login.
        Process::assertRan(fn (PendingProcess $p) => $p->command === ['/opt/stripe', 'listen', '--print-secret']
            && ($p->environment['STRIPE_API_KEY'] ?? null) === false);
    }

    public function test_a_cli_that_is_not_signed_in_or_not_there_gives_nothing(): void
    {
        Process::fake(['*' => Process::result('', 'You have not configured API keys yet. Running `stripe login`...', 1)]);
        $this->assertNull(app(StripeForwarder::class)->signingSecret());

        Process::fake(['*' => Process::result("Checking for new versions...\n")]);
        $this->assertNull(app(StripeForwarder::class)->signingSecret());
    }

    public function test_it_forwards_only_the_events_a_payment_needs_in_test_mode_once_ready(): void
    {
        Process::fake(['*' => Process::result('', "> Ready! You are using Stripe API Version [2024-06-20]. Your webhook signing secret is whsec_abc123 (^C to quit)\n")]);
        $forwarder = app(StripeForwarder::class);

        $process = $forwarder->start('http://dcos.travischapman.practice.test/webhooks/stripe');
        $forwarder->stop($process);

        Process::assertRan(fn (PendingProcess $p) => $p->command === [
            '/opt/stripe', 'listen',
            '--forward-to', 'http://dcos.travischapman.practice.test/webhooks/stripe',
            '--events', 'checkout.session.completed',
        ] && $p->timeout === null);
        Process::assertDidntRun(fn (PendingProcess $p) => in_array('--live', (array) $p->command, true));
    }

    public function test_a_cli_that_stops_before_it_is_ready_says_why_without_its_secret(): void
    {
        Process::fake(['*' => Process::result('', "Your webhook signing secret is whsec_abc123\nError: unable to connect to Stripe\n", 1)]);

        try {
            app(StripeForwarder::class)->start('http://dcos.travischapman.practice.test/webhooks/stripe');
            $this->fail('It started.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('unable to connect to Stripe', $e->getMessage());
            $this->assertStringNotContainsString('whsec_', $e->getMessage());
        }
    }

    public function test_stopping_nothing_is_fine(): void
    {
        Process::fake();

        app(StripeForwarder::class)->stop(null);

        Process::assertNothingRan();
    }
}
