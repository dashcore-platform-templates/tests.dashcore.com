<?php

namespace Tests\Feature\Building;

use App\Building\BuildPlanner;
use App\Enums\BuildStatus;
use App\Enums\SurfaceChange;
use App\Enums\SurfaceKind;
use App\Enums\SurfaceStatus;
use App\Enums\VerdictReason;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Models\Surface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildPlannerTest extends TestCase
{
    use RefreshDatabase;

    private FleetApp $fleetApp;

    private Scan $scan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fleetApp = FleetApp::factory()->create();
        $this->scan = Scan::factory()->completed()->for($this->fleetApp)->create();
    }

    private function surface(string $identifier, SurfaceStatus $status, array $attributes = []): Surface
    {
        return $this->fleetApp->surfaces()->create([
            'kind' => SurfaceKind::Service,
            'identifier' => $identifier,
            'label' => $identifier,
            'status' => $status,
            ...$attributes,
        ]);
    }

    private function plan(?Surface $lead = null): array
    {
        return app(BuildPlanner::class)->plan($this->fleetApp, $lead)->pluck('identifier')->all();
    }

    public function test_the_most_urgent_gap_leads_and_its_neighbours_follow(): void
    {
        $this->surface('Pricing::total', SurfaceStatus::Missing, ['file' => 'app/Services/Pricing.php', 'start_line' => 20]);
        $this->surface('Pricing::tax', SurfaceStatus::Weak, ['file' => 'app/Services/Pricing.php', 'start_line' => 10, 'reason' => VerdictReason::MutantSurvived]);
        $this->surface('Pricing::round', SurfaceStatus::Tested, ['file' => 'app/Services/Pricing.php']);
        $this->surface('Mailer::send', SurfaceStatus::Missing, ['file' => 'app/Services/Mailer.php']);
        $this->surface('GET reports', SurfaceStatus::Missing, ['kind' => SurfaceKind::Route]);
        $this->surface('POST reports/{id}', SurfaceStatus::Weak, ['kind' => SurfaceKind::Route]);
        $this->surface('GET exports', SurfaceStatus::Missing, ['kind' => SurfaceKind::Route]);

        $this->assertSame(['GET reports', 'POST reports/{id}'], $this->plan(), 'a route leads, and routes group by their first segment');
    }

    public function test_a_regression_leads_before_anything_else(): void
    {
        $this->surface('GET reports', SurfaceStatus::Missing, ['kind' => SurfaceKind::Route]);
        $lost = $this->surface('Pricing::total', SurfaceStatus::Missing, ['file' => 'app/Services/Pricing.php']);
        $this->scan->surfaceResults()->create(['surface_id' => $lost->id, 'status' => 'missing', 'change' => SurfaceChange::Lost]);

        $this->assertSame(['Pricing::total'], $this->plan());
    }

    public function test_a_batch_is_capped(): void
    {
        config(['scanner.build.targets' => 2]);

        foreach (range(1, 4) as $i) {
            $this->surface("Pricing::m{$i}", SurfaceStatus::Missing, ['file' => 'app/Services/Pricing.php', 'start_line' => $i]);
        }

        $this->assertCount(2, $this->plan());
    }

    public function test_surfaces_held_by_a_running_build_or_a_waiting_pr_are_not_offered_again(): void
    {
        $a = $this->surface('A::a', SurfaceStatus::Missing, ['file' => 'app/A.php']);
        $b = $this->surface('B::b', SurfaceStatus::Missing, ['file' => 'app/B.php']);
        $c = $this->surface('C::c', SurfaceStatus::Missing, ['file' => 'app/C.php']);
        $this->fleetApp->builds()->create(['status' => BuildStatus::Opened, 'targets' => [$a->id]]);
        $this->fleetApp->builds()->create(['status' => BuildStatus::Writing, 'targets' => [$b->id]]);
        $this->fleetApp->builds()->create(['status' => BuildStatus::Rejected, 'targets' => [$c->id]]);

        $this->assertSame(['C::c'], $this->plan(), 'a rejected build gives its targets back');
    }

    public function test_a_chosen_surface_leads_its_own_batch_if_it_is_open(): void
    {
        $this->surface('GET reports', SurfaceStatus::Missing, ['kind' => SurfaceKind::Route]);
        $chosen = $this->surface('Mailer::send', SurfaceStatus::Missing, ['file' => 'app/Services/Mailer.php']);
        $tested = $this->surface('Mailer::queue', SurfaceStatus::Tested, ['file' => 'app/Services/Mailer.php']);

        $this->assertSame(['Mailer::send'], $this->plan($chosen));
        $this->assertSame([], $this->plan($tested), 'a tested surface needs no build');
    }

    public function test_an_unscanned_app_or_one_with_no_gaps_has_nothing_to_build(): void
    {
        $this->assertSame([], $this->plan());

        $this->surface('A::a', SurfaceStatus::Missing, ['file' => 'app/A.php', 'removed_at' => now()]);
        $this->assertSame([], $this->plan(), 'removed surfaces are not gaps');

        $fresh = FleetApp::factory()->create();
        $fresh->surfaces()->create(['kind' => 'service', 'identifier' => 'x', 'label' => 'x', 'status' => 'missing']);
        $this->assertTrue(app(BuildPlanner::class)->plan($fresh)->isEmpty(), 'no completed scan to plan from');
    }
}
