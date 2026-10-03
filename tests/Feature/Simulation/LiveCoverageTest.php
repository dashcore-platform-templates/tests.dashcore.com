<?php

namespace Tests\Feature\Simulation;

use App\Enums\SurfaceKind;
use App\Enums\SurfaceStatus;
use App\Livewire\Fleet\Show;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Models\SimulationReach;
use App\Models\SimulationRun;
use App\Models\User;
use App\Simulation\LiveCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LiveCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function salesApp(): FleetApp
    {
        $app = FleetApp::factory()->create(['key' => 'sales', 'name' => 'Sales', 'host' => 'sales.dashcore.com']);

        foreach ([
            [SurfaceKind::Route, 'GET leads', 'GET /leads', SurfaceStatus::Tested],
            [SurfaceKind::Route, 'GET leads/{lead}', 'GET /leads/{lead}', SurfaceStatus::Missing],
            [SurfaceKind::Route, 'GET reports/{period?}', 'GET /reports/{period?}', SurfaceStatus::Tested],
            [SurfaceKind::Route, 'POST leads', 'POST /leads', SurfaceStatus::Tested],
            [SurfaceKind::Route, 'GET settings', 'GET /settings', SurfaceStatus::Missing],
            [SurfaceKind::Component, 'App\Livewire\Leads\Index', 'leads', SurfaceStatus::Tested],
            [SurfaceKind::Component, 'App\Livewire\Leads\Show', 'leads.show', SurfaceStatus::Tested],
            [SurfaceKind::Action, 'App\Livewire\Leads\Index::createLead', 'leads@createLead', SurfaceStatus::Weak],
            [SurfaceKind::Action, 'App\Livewire\Leads\Index::deleteLead', 'leads@deleteLead', SurfaceStatus::Missing],
            [SurfaceKind::Job, 'App\Jobs\Sync::handle', 'Sync::handle', SurfaceStatus::Missing],
        ] as [$kind, $identifier, $label, $status]) {
            $app->surfaces()->create(['kind' => $kind, 'identifier' => $identifier, 'label' => $label, 'status' => $status]);
        }

        return $app;
    }

    private function reached(array $reached, string $target = 'sales'): SimulationRun
    {
        $run = SimulationRun::factory()->create(['target' => $target, 'reached' => $reached]);
        app(LiveCoverage::class)->record($run);

        return $run;
    }

    public function test_a_steps_report_is_folded_in_with_ids_generalised_and_livewires_own_calls_dropped(): void
    {
        $merged = LiveCoverage::merge(
            ['pages' => ['GET /leads'], 'calls' => [], 'mounts' => ['leads.index']],
            ['pages' => ['GET /leads/281', 'GET /leads', 'GET /deals/9/notes/12'], 'calls' => ['leads.index@$commit', 'leads.index@createLead', 'leads.index@$refresh'], 'mounts' => ['leads.index', 'leads.show']],
        );

        $this->assertSame([
            'pages' => ['GET /leads', 'GET /leads/{n}', 'GET /deals/{n}/notes/{n}'],
            'calls' => ['leads.index@createLead'],
            'mounts' => ['leads.index', 'leads.show'],
        ], $merged);
    }

    public function test_the_tally_counts_each_thing_once_per_run(): void
    {
        $first = $this->reached(['pages' => ['GET /leads'], 'calls' => ['leads.index@createLead']]);
        $second = $this->reached(['pages' => ['GET /leads']]);

        $page = SimulationReach::query()->where('kind', SimulationReach::PAGE)->sole();
        $this->assertSame([2, $second->id], [$page->hits, $page->last_run_id]);
        $this->assertSame(1, SimulationReach::query()->where('kind', SimulationReach::CALL)->sole()->hits);
        $this->assertNotNull($first);
    }

    public function test_reach_maps_onto_routes_components_and_actions(): void
    {
        $this->salesApp();
        $this->reached([
            'pages' => ['GET /leads', 'GET /leads/{n}', 'GET /reports', 'GET /login'],
            'calls' => ['leads.index@createLead', 'leads.show@logActivity'],
            'mounts' => ['leads.index'],
        ]);

        $coverage = app(LiveCoverage::class)->forTarget('sales');

        // GET leads, GET leads/{lead}, GET reports/{period?}; leads, leads.show (by its call); leads@createLead.
        $this->assertSame([6, 9], [$coverage['reached'], $coverage['total']]);
        $this->assertSame(['total' => 5, 'reached' => 3], array_intersect_key($coverage['kinds']['route'], ['total' => 0, 'reached' => 0]));
        $this->assertSame(['GET /leads/{lead}', 'leads@createLead'], $coverage['unproven']->pluck('label')->all());
        $this->assertSame(['leads.show@logActivity'], $coverage['unmapped']);
    }

    public function test_a_target_not_in_the_fleet_has_nothing_to_map_onto(): void
    {
        $this->reached(['calls' => ['leads.index@createLead']]);

        $coverage = app(LiveCoverage::class)->forTarget('sales');

        $this->assertNull($coverage['app']);
        $this->assertSame(0, $coverage['total']);
        $this->assertSame(['leads.index@createLead'], $coverage['unmapped']);
    }

    public function test_a_target_can_name_the_fleet_app_it_runs(): void
    {
        $this->salesApp();
        config(['simulation.targets.sales.fleet_app' => 'crm']);

        $this->assertNull(app(LiveCoverage::class)->forTarget('sales')['app']);
    }

    public function test_the_apps_coverage_page_marks_surfaces_used_live(): void
    {
        $app = $this->salesApp();
        Scan::factory()->completed()->for($app)->create();
        $this->reached(['pages' => ['GET /leads/{n}']]);
        $this->actingAs(User::factory()->create());

        Livewire::test(Show::class, ['appKey' => $app->key])
            ->set('status', 'all')
            ->assertSeeHtml('title="A Proving Ground journey uses this in a real browser"');

        $this->assertSame(
            [$app->surfaces()->where('identifier', 'GET leads/{lead}')->value('id')],
            app(LiveCoverage::class)->reachedSurfaceIds($app),
        );
    }
}
