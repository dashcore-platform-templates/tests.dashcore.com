<?php

namespace Tests\Feature\Simulation;

use App\Simulation\Simulation;
use App\Simulation\StripeForwarder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `tests:launch`: a client's whole signup flown in one go, after a
 * countdown that scrubs it on the pad rather than halfway up.
 */
class LaunchTest extends TestCase
{
    private string $root;

    private ?string $cliSecret = 'whsec_cli_signing_secret_for_tests';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/launch-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists("{$this->root}/fleets");
        File::put("{$this->root}/fleets/artisan", '');

        config([
            'simulation.screenshots' => "{$this->root}/shots",
            'simulation.fleets.practice.fleets_checkout' => "{$this->root}/fleets",
            'simulation.fleets.practice.apps' => [
                'website' => ['name' => 'Website', 'base_url' => 'https://jane.practice.test', 'checkout' => "{$this->root}/jane.practice"],
                'sales' => ['name' => 'Sales', 'base_url' => 'https://sales.jane.practice.test', 'checkout' => "{$this->root}/sales.jane.practice"],
            ],
        ]);

        $test = $this;
        $this->app->instance(StripeForwarder::class, new class(app(Simulation::class), $test) extends StripeForwarder
        {
            public function __construct(Simulation $simulation, private readonly LaunchTest $test)
            {
                parent::__construct($simulation);
            }

            public function signingSecret(): ?string
            {
                return $this->test->cliSecret();
            }
        });

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function cliSecret(): ?string
    {
        return $this->cliSecret;
    }

    /** @return array<string, mixed> */
    private function flight(): array
    {
        return json_decode(File::get(File::files("{$this->root}/shots/launches")[0]->getPathname()), true);
    }

    public function test_a_countdown_that_is_not_go_flies_nothing_and_says_what_to_fix(): void
    {
        $this->cliSecret = null;
        Http::fake(['https://sales.jane.practice.test/up' => Http::response('', 502), '*/up' => Http::response('ok')]);
        config(['simulation.launch' => ['enquiry']]);

        $this->artisan('tests:launch')
            ->expectsOutputToContain('run `stripe login` on this machine')
            ->expectsOutputToContain('https://sales.jane.practice.test/up answered 502')
            ->expectsOutputToContain('Scrubbed on the pad')
            ->doesntExpectOutputToContain('Stage 1 of 1')
            ->assertFailed();

        $this->assertSame(['scrubbed', []], [$this->flight()['outcome'], $this->flight()['stages']]);
    }

    public function test_the_first_stage_that_fails_ends_the_flight(): void
    {
        // first-briefing is pending, so tests:journey refuses it at once.
        config(['simulation.launch' => ['first-briefing', 'enquiry']]);
        Http::fake(['*/up' => Http::response('ok')]);

        $this->artisan('tests:launch')
            ->expectsOutputToContain('Stage 1 of 2 — first-briefing')
            ->expectsOutputToContain('The launch failed at stage 1 of 2, first-briefing')
            ->doesntExpectOutputToContain('Stage 2 of 2')
            ->assertFailed();

        $flight = $this->flight();
        $this->assertSame('failed', $flight['outcome']);
        $this->assertSame(['first-briefing'], array_column($flight['stages'], 'stage'));
        $this->assertFalse($flight['stages'][0]['passed']);
        $this->assertTrue(collect($flight['countdown'])->every(fn (array $check): bool => $check['go']));
    }

    public function test_the_launch_flies_subscription_then_provisioning_then_first_sign_in(): void
    {
        $this->assertSame(['subscription', 'provisioning', 'first-sign-in'], config('simulation.launch'));
    }
}
