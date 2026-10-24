<?php

namespace Tests\Feature\Simulation;

use App\Livewire\Simulation\JourneyMap;
use App\Models\Launch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The client journey map: the latest launch, drawn stage by stage, app by
 * app, with each step's screenshot — and nothing it did not record.
 */
class JourneyMapPageTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/pg-map-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists("{$this->root}/journeys/first-sign-in-1");
        File::put("{$this->root}/journeys/first-sign-in-1/001.jpg", 'jpeg');
        File::put("{$this->root}/secret.jpg", 'not a step');

        config([
            'simulation.screenshots' => $this->root,
            'simulation.launch' => ['subscription', 'provisioning', 'first-sign-in', 'first-briefing', 'onboarding-call'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function launch(array $overrides = []): Launch
    {
        return Launch::query()->create([
            'target' => 'practice',
            'outcome' => Launch::FAILED,
            'countdown' => [['check' => 'Stripe CLI signed in', 'go' => true, 'why' => null]],
            'stages' => [
                [
                    'stage' => 'subscription', 'name' => 'Subscription', 'journey' => 'The prospect is sold the plan', 'passed' => true, 'seconds' => 33.2,
                    'apps' => ['website' => ['name' => 'Website', 'base_url' => 'https://travischapman.practice.test'], 'sales' => ['name' => 'Sales', 'base_url' => 'https://sales.travischapman.practice.test']],
                    'walks' => [[
                        'shots' => "{$this->root}/journeys/subscription-1",
                        'summary' => 'Amy was sold the plan',
                        'said' => [],
                        'steps' => [
                            ['position' => 0, 'do' => 'visit', 'on' => 'website', 'status' => 'passed', 'ms' => 700, 'label' => 'The prospect opens the contact page', 'screenshot' => '000.jpg'],
                            ['position' => 1, 'do' => 'mail', 'on' => 'sales', 'status' => 'passed', 'ms' => 200, 'label' => 'The payment link arrives in their email', 'screenshot' => '001.jpg'],
                        ],
                        'not_reached' => [], 'server_errors' => [], 'error' => null,
                    ]],
                    'said' => [], 'errors' => [],
                ],
                [
                    'stage' => 'provisioning', 'name' => 'Provisioning', 'journey' => 'The paid order builds the fleet', 'passed' => true, 'seconds' => 410.0,
                    'apps' => [], 'walks' => [[
                        'shots' => "{$this->root}/journeys/provisioning-1", 'summary' => 'Built', 'said' => ['Spencer Workshop paid for API + CEO: fleets builds it now.'],
                        'steps' => [['position' => 0, 'do' => 'visit', 'on' => 'cockpit', 'status' => 'passed', 'ms' => 500, 'label' => 'Their cockpit is opened', 'screenshot' => null]],
                        'not_reached' => [], 'server_errors' => [], 'error' => null,
                    ]],
                    'said' => [], 'errors' => [],
                ],
                [
                    'stage' => 'first-sign-in', 'name' => 'First sign-in', 'journey' => 'The owner signs in', 'passed' => false, 'seconds' => 8.0,
                    'apps' => ['cockpit' => ['name' => "Spencer Workshop's cockpit", 'base_url' => 'https://ceo.spencer-workshop.practice.dashcore.com']],
                    'walks' => [[
                        'shots' => "{$this->root}/journeys/first-sign-in-1", 'summary' => '', 'said' => ['login sent them nothing, so the build fell back to ceo\'s own password link.'],
                        'steps' => [
                            ['position' => 0, 'do' => 'mail', 'on' => 'cockpit', 'status' => 'passed', 'ms' => 300, 'label' => "ceo's own link arrives", 'screenshot' => '000.jpg'],
                            ['position' => 1, 'do' => 'visit', 'on' => 'cockpit', 'status' => 'failed', 'ms' => 900, 'label' => 'They open the link', 'error' => 'This password reset token is invalid.', 'screenshot' => '001.jpg'],
                        ],
                        'not_reached' => ['Their dashboard greets Amy'], 'server_errors' => [], 'error' => null,
                    ]],
                    'said' => [], 'errors' => ['First sign-in failed after 1 of 3 steps.'],
                ],
            ],
            'teardown' => [['stage' => 'cancellation', 'name' => 'Cancellation', 'passed' => true]],
            'started_at' => now()->subMinutes(20),
            'finished_at' => now()->subMinutes(5),
            ...$overrides,
        ]);
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get(route('simulation.journey-map'))->assertRedirect(route('login'));
    }

    public function test_with_no_launch_yet_it_says_how_to_fly_one_and_it_is_in_the_navigation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('simulation.journey-map'))
            ->assertOk()
            ->assertSeeLivewire(JourneyMap::class)
            ->assertSee('No launch has been flown yet.')
            ->assertSee('php artisan tests:launch')
            ->assertSee(route('simulation.journey-map'));
    }

    public function test_the_latest_launch_is_drawn_stage_by_stage_and_opens_where_it_broke(): void
    {
        $this->launch(['started_at' => now()->subDays(2), 'finished_at' => now()->subDays(2), 'outcome' => Launch::LAUNCHED]);
        $this->launch();
        $this->actingAs(User::factory()->create());

        Livewire::test(JourneyMap::class)
            ->assertSeeInOrder(['Subscription', 'Provisioning', 'First sign-in', 'First briefing', 'Onboarding call'])
            ->assertSee('Broke here')
            ->assertSee('Not reached')
            ->assertSee('Failed')
            // It opens on the stage that broke.
            ->assertSee('The owner signs in')
            ->assertSee('This password reset token is invalid.')
            ->assertSee('login sent them nothing, so the build fell back')
            ->assertSee('Their dashboard greets Amy')
            ->assertSee('First sign-in failed after 1 of 3 steps.')
            ->assertSee('ceo.spencer-workshop.practice.dashcore.com')
            ->assertSee('taken down again (Cancellation): done');
    }

    public function test_a_stage_shows_its_hops_and_how_the_client_crossed_between_apps(): void
    {
        $launch = $this->launch();
        $this->actingAs(User::factory()->create());

        Livewire::test(JourneyMap::class)
            ->call('openStage', 'subscription')
            ->assertSeeInOrder(['Website', 'The prospect opens the contact page', 'by email, to Sales', 'The payment link arrives in their email'])
            ->call('openStage', 'provisioning')
            ->assertSee('Outside the browser')
            ->assertSee('fleets builds it now')
            ->assertSee('No screenshot for this stage.')
            ->call('openStage', 'first-sign-in')
            ->call('showShot', 0, 1)
            ->assertSee(route('simulation.journey-map.shot', [$launch, 'first-sign-in', 0, '001.jpg']), escape: false);
    }

    public function test_an_earlier_launch_can_be_picked(): void
    {
        $earlier = $this->launch(['outcome' => Launch::SCRUBBED, 'stages' => [], 'teardown' => [], 'countdown' => [['check' => 'Stripe CLI signed in', 'go' => false, 'why' => 'run `stripe login` on this machine']]]);
        $this->launch();
        $this->actingAs(User::factory()->create());

        Livewire::test(JourneyMap::class)
            ->assertDontSee('Scrubbed on the pad')
            ->call('pick', $earlier->id)
            ->assertSee('Scrubbed on the pad: nothing was flown.')
            ->assertSee('run `stripe login` on this machine');
    }

    public function test_a_steps_screenshot_is_served_and_nothing_outside_the_screenshots_folder_is(): void
    {
        $launch = $this->launch();
        $this->actingAs(User::factory()->create());

        $this->get(route('simulation.journey-map.shot', [$launch, 'first-sign-in', 0, '001.jpg']))->assertOk();
        $this->get(route('simulation.journey-map.shot', [$launch, 'first-sign-in', 0, '002.jpg']))->assertNotFound();
        $this->get(route('simulation.journey-map.shot', [$launch, 'onboarding-call', 0, '001.jpg']))->assertNotFound();
        $this->get("/client-journey/{$launch->id}/first-sign-in/0/..%2F..%2Fsecret.jpg")->assertNotFound();
    }
}
