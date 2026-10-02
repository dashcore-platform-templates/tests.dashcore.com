<?php

namespace Tests\Feature\Fleet;

use App\Enums\BuildStatus;
use App\Enums\SurfaceStatus;
use App\Jobs\RunBuild;
use App\Livewire\Builds\Show as BuildShow;
use App\Livewire\Fleet\Index;
use App\Livewire\Fleet\Show;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Models\Surface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\UsesFleetFixtures;
use Tests\TestCase;

class BuildPagesTest extends TestCase
{
    use RefreshDatabase, UsesFleetFixtures;

    private FleetApp $fleetApp;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $root = $this->scannerRootWith(['finance.dashcore.com']);
        $this->fleetApp = FleetApp::factory()->create(['key' => 'finance', 'name' => 'Finance', 'host' => 'finance.dashcore.com', 'local_path' => $root.'/finance.dashcore.com']);
        Scan::factory()->completed()->for($this->fleetApp)->create(['surfaces_missing' => 2]);
    }

    private function gap(string $identifier): Surface
    {
        return $this->fleetApp->surfaces()->create(['kind' => 'service', 'identifier' => $identifier, 'label' => $identifier, 'status' => SurfaceStatus::Missing, 'file' => 'app/'.$identifier.'.php']);
    }

    public function test_owners_and_admins_can_ask_for_tests_and_members_cannot(): void
    {
        $this->gap('Pricing');

        Livewire::actingAs(User::factory()->create())
            ->test(Show::class, ['appKey' => 'finance'])
            ->assertDontSee('Write tests for gaps')
            ->assertDontSee('Write a test')
            ->call('build')
            ->assertForbidden();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Show::class, ['appKey' => 'finance'])
            ->assertSee('Write tests for gaps')
            ->call('build')
            ->assertDispatched('fleet-notice', message: 'Build queued for 1 surface. An agent will write the tests; a PR opens only if they pass verification.');

        Queue::assertPushed(RunBuild::class, 1);
    }

    public function test_a_test_can_be_asked_for_one_surface_and_it_then_shows_as_held(): void
    {
        $this->gap('Pricing');
        $mailer = $this->gap('Mailer');

        $component = Livewire::actingAs(User::factory()->owner()->create())
            ->test(Show::class, ['appKey' => 'finance'])
            ->call('build', $mailer->id);

        $build = $this->fleetApp->builds()->sole();
        $this->assertSame([$mailer->id], $build->targets);

        $component->assertSee('In build #'.$build->id)
            ->call('build')
            ->assertDispatched('fleet-notice', message: 'A build is already running for this app.');
    }

    public function test_nothing_left_to_build_says_so(): void
    {
        $held = $this->gap('Pricing');
        $this->fleetApp->builds()->create(['status' => BuildStatus::Opened, 'targets' => [$held->id], 'pr_url' => 'https://github.com/x/y/pull/4', 'pr_number' => 4]);

        Livewire::actingAs(User::factory()->owner()->create())
            ->test(Show::class, ['appKey' => 'finance'])
            ->assertSee('PR #4')
            ->call('build')
            ->assertDispatched('fleet-notice', message: 'Nothing to build: every open surface is already covered by a build or a PR waiting on review.');

        Livewire::actingAs(User::factory()->create())->test(Index::class)->assertSee('1 test PR to review');
    }

    public function test_the_build_page_shows_each_verdict_the_agents_report_and_the_pr(): void
    {
        $pricing = $this->gap('Pricing');
        $mailer = $this->gap('Mailer');
        $build = $this->fleetApp->builds()->create([
            'status' => BuildStatus::Opened,
            'targets' => [$pricing->id, $mailer->id],
            'targets_proven' => 1,
            'tests_added' => 3,
            'cost_usd' => 0.42,
            'pr_url' => 'https://github.com/x/y/pull/4',
            'pr_number' => 4,
            'changed_files' => ['tests/Feature/PricingTest.php'],
            'verification' => ['targets' => [
                $pricing->id => ['label' => 'Pricing', 'status' => 'tested', 'mutation' => 'killed', 'proven' => true, 'tests' => ['totals']],
                $mailer->id => ['label' => 'Mailer', 'status' => 'missing', 'mutation' => null, 'proven' => false, 'tests' => []],
            ]],
            'agent_report' => ['suspected_bugs' => [['target' => (string) $mailer->id, 'description' => 'sends twice']], 'skipped' => []],
        ]);

        $this->actingAs(User::factory()->create())->get(route('builds.show', $build))->assertOk();

        Livewire::actingAs(User::factory()->create())
            ->test(BuildShow::class, ['buildId' => $build->id])
            ->assertSee('1 of 2 targets proven')
            ->assertSee('agent cost $0.42')
            ->assertSee('PR #4')
            ->assertSee('Proven')
            ->assertSee('mutation caught')
            ->assertSee('✓ totals', false)
            ->assertSee('Not proven')
            ->assertSee('Suspected bugs the agent left untested')
            ->assertSee('sends twice')
            ->assertSee('tests/Feature/PricingTest.php');
    }

    public function test_a_rejected_build_says_why_it_was_not_proposed(): void
    {
        $build = $this->fleetApp->builds()->create(['status' => BuildStatus::Rejected, 'targets' => [], 'error' => "A new test asserts nothing: hollow\nNone of the targets is proven by the new tests."]);

        Livewire::actingAs(User::factory()->create())
            ->test(BuildShow::class, ['buildId' => $build->id])
            ->assertSee('Not proposed — it did not pass verification')
            ->assertSee('A new test asserts nothing: hollow');
    }

    public function test_the_build_command_queues_apps_with_gaps_and_skips_the_rest(): void
    {
        $this->gap('Pricing');
        FleetApp::factory()->create(['key' => 'hr']);

        $this->artisan('tests:build')
            ->expectsOutputToContain('queued 1 target(s)')
            ->expectsOutputToContain('nothing to build')
            ->assertSuccessful();

        $this->artisan('tests:build')->expectsOutputToContain('a build is running or waiting on review')->assertSuccessful();
    }

    public function test_the_build_command_checks_its_surface_option(): void
    {
        $this->artisan('tests:build', ['--surface' => 999])->expectsOutputToContain('--surface needs exactly one app')->assertFailed();
        $this->artisan('tests:build', ['app' => ['nope']])->expectsOutputToContain('Not in the fleet: nope')->assertFailed();
    }

    public function test_the_fleet_baseline_can_be_installed_from_the_app_page_by_owners_and_admins(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Index::class)->assertSee('No fleet baseline');

        Livewire::actingAs(User::factory()->create())
            ->test(Show::class, ['appKey' => 'finance'])
            ->assertDontSee('Install fleet baseline')
            ->call('installBaseline')
            ->assertForbidden();

        Livewire::actingAs(User::factory()->owner()->create())
            ->test(Show::class, ['appKey' => 'finance'])
            ->assertSee('No fleet baseline')
            ->call('installBaseline')
            ->assertDispatched('fleet-notice', message: 'Fleet baseline install queued. A PR opens once it passes here.');

        Queue::assertPushed(RunBuild::class, fn (RunBuild $job) => $job->build->kind->value === 'testkit');

        $this->fleetApp->scans()->update(['testkit_version' => '0.1.3']);

        Livewire::actingAs(User::factory()->owner()->create())
            ->test(Show::class, ['appKey' => 'finance'])
            ->assertSee('Fleet baseline 0.1.3')
            ->assertDontSee('Install fleet baseline');
    }
}
