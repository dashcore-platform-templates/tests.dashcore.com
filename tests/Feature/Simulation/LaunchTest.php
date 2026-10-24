<?php

namespace Tests\Feature\Simulation;

use App\Models\Launch;
use App\Simulation\CustomerJourney;
use App\Simulation\Script;
use App\Simulation\Simulation;
use App\Simulation\StripeForwarder;
use Faker\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * `tests:launch`: a client's whole signup flown in one go, after a
 * countdown that scrubs it on the pad rather than halfway up.
 */
class LaunchTest extends TestCase
{
    use RefreshDatabase;

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
        // A pending stage, so tests:journey refuses it at once.
        config([
            'simulation.customer_journeys.renewal' => ['name' => 'Renewal', 'what' => 'The customer renews.'],
            'simulation.launch' => ['renewal', 'enquiry'],
        ]);
        Http::fake(['*/up' => Http::response('ok')]);

        $this->artisan('tests:launch')
            ->expectsOutputToContain('Stage 1 of 2 — renewal')
            ->expectsOutputToContain('The launch failed at stage 1 of 2, renewal')
            ->doesntExpectOutputToContain('Stage 2 of 2')
            ->doesntExpectOutputToContain('Teardown')
            ->assertFailed();

        $flight = $this->flight();
        $this->assertSame('failed', $flight['outcome']);
        $this->assertSame(['renewal'], array_column($flight['stages'], 'stage'));
        $this->assertFalse($flight['stages'][0]['passed']);
        $this->assertSame('Renewal', $flight['stages'][0]['name']);
        $this->assertSame([], $flight['teardown'], 'Nothing was built, so nothing is taken down.');
        $this->assertTrue(collect($flight['countdown'])->every(fn (array $check): bool => $check['go']));

        $launch = Launch::query()->sole();
        $this->assertSame([Launch::FAILED, 'practice', ['renewal']], [$launch->outcome, $launch->target, array_column($launch->stages, 'stage')]);
    }

    public function test_each_stage_is_recorded_step_by_step_and_a_built_fleet_is_taken_down_again(): void
    {
        config([
            'simulation.customer_journeys.provisioning' => ['name' => 'Provisioning', 'journey' => LaunchTestBuild::class],
            'simulation.customer_journeys.renewal' => ['name' => 'Renewal', 'what' => 'The customer renews.'],
            'simulation.customer_journeys.first-sign-in' => ['name' => 'First sign-in', 'journey' => LaunchTestBuild::class],
            'simulation.launch' => ['provisioning', 'first-sign-in'],
            'simulation.launch_teardown' => ['renewal'],
        ]);
        File::ensureDirectoryExists("{$this->root}/jane.practice");
        Http::fake(['*/up' => Http::response('ok')]);
        Process::fake(function (PendingProcess $process) {
            if ((((array) $process->command)[1] ?? null) === '-r') {
                return Process::result("\n@@simulation-config@@".json_encode(['mail.default' => 'log'])."\n");
            }

            return Process::result(implode("\n", [
                json_encode(['event' => 'started']),
                json_encode(['event' => 'step', 'position' => 0, 'status' => 'passed', 'ms' => 120, 'url' => 'https://jane.practice.test/', 'error' => null, 'screenshot' => '000.jpg']),
                json_encode(['event' => 'step', 'position' => 1, 'status' => 'passed', 'ms' => 80, 'url' => 'https://jane.practice.test/', 'error' => null, 'screenshot' => '001.jpg']),
                json_encode(['event' => 'finished', 'status' => 'passed']),
            ])."\n");
        });

        $this->artisan('tests:launch')
            ->expectsOutputToContain('Launched: all 2 stages flew')
            ->expectsOutputToContain('Teardown — renewal')
            ->assertSuccessful();

        $launch = Launch::query()->sole();
        $this->assertTrue($launch->launched());

        $stage = $launch->stages[0];
        $this->assertSame(['provisioning', true, 'Provisioning'], [$stage['stage'], $stage['passed'], $stage['name']]);
        $this->assertSame(['The fleet is built, at https://jane.practice.test.'], $stage['walks'][0]['said']);
        $this->assertSame('Jane built a fleet', $stage['walks'][0]['summary']);
        $this->assertSame(['They open the website', 'They type a password'], array_column($stage['walks'][0]['steps'], 'label'));
        $this->assertSame('website', $stage['walks'][0]['steps'][0]['on']);
        $this->assertArrayNotHasKey('value', $stage['walks'][0]['steps'][1], 'A typed value is never kept.');
        $this->assertSame('https://jane.practice.test', $stage['apps']['website']['base_url']);

        // Each stage holds its own walk only, though one command walked both.
        $this->assertCount(1, $launch->stages[1]['walks']);
        $this->assertSame(['The fleet is built, at https://jane.practice.test.'], $launch->stages[1]['walks'][0]['said']);

        $this->assertSame(['renewal'], array_column($launch->teardown, 'stage'));
    }

    public function test_the_launch_brings_a_client_on_from_enquiry_to_the_onboarding_call(): void
    {
        $this->assertSame(['subscription', 'provisioning', 'first-sign-in', 'first-briefing', 'onboarding-call'], config('simulation.launch'));
        $this->assertSame(['cancellation'], config('simulation.launch_teardown'));
    }
}

/** A stage that "builds" in one browser walk on the website, for the launch's record. */
class LaunchTestBuild extends CustomerJourney
{
    public function name(): string
    {
        return 'A stand-in build';
    }

    public function prepare(array $fleet, callable $say): array
    {
        $say('The fleet is built, at https://jane.practice.test.');

        return [];
    }

    public function script(array $people, Generator $faker): Script
    {
        return (new Script)->on('website')
            ->visit('/', 'They open the website')
            ->fill('#password', 'a-secret-password', 'They type a password')
            ->summary('Jane built a fleet');
    }
}
