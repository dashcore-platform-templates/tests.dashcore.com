<?php

namespace Tests\Feature\Simulation;

use App\Services\FleetExport;
use App\Simulation\PracticeFleet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * The practice fleet journeys walk comes from fleets' roster. Built from the
 * Mac's user it once named practice-travischapman, which fleets has never
 * had, and every Enquiry walk refused (REQ-683).
 */
class PracticeFleetTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, array<string, mixed>>  $fleets */
    private function roster(array $fleets): void
    {
        $export = Mockery::mock(FleetExport::class);
        $export->shouldReceive('fleets')->andReturn($fleets);
        $this->app->instance(FleetExport::class, $export);
        Cache::forget(PracticeFleet::CACHE_KEY);
    }

    private function freshConfig(): void
    {
        // phpunit.xml names a practice fleet; these tests read the roster.
        config(['simulation.fleets' => ['practice' => [...(require config_path('simulation.php'))['fleets']['practice'], 'install' => '{install}', 'domain' => '{domain}']]]);
    }

    private function fleets(): array
    {
        return [
            'dashcore' => ['slug' => 'dashcore', 'domain' => 'dashcore.com', 'practice' => false],
            'practice-req145' => ['slug' => 'practice-req145', 'domain' => 'req145.practice', 'practice' => true],
            'chapman-and-associates' => ['slug' => 'chapman-and-associates', 'domain' => 'chapmanandassoc.com', 'practice' => true],
            'starter-rehearsal' => ['slug' => 'starter-rehearsal', 'domain' => 'starter-rehearsal.practice', 'practice' => true],
        ];
    }

    public function test_the_one_practice_fleet_named_practice_star_is_walked_with_its_roster_domain(): void
    {
        $this->roster($this->fleets());
        $this->freshConfig();

        PracticeFleet::ensure();

        $fleet = config('simulation.fleets.practice');
        $this->assertSame('practice-req145', $fleet['install']);
        $this->assertSame('req145.practice', $fleet['domain']);
        $this->assertNull(PracticeFleet::refusal($fleet));
        $this->assertSame('Practice fleet (practice-req145)', $fleet['name']);
        $this->assertSame('https://req145.practice.test', $fleet['apps']['website']['base_url']);
        $this->assertStringEndsWith('/req145.practice', $fleet['apps']['website']['checkout']);
        $this->assertSame('https://sales.req145.practice.test', $fleet['apps']['sales']['base_url']);
        $this->assertStringEndsWith('/sales.req145.practice', $fleet['apps']['sales']['checkout']);
        $this->assertStringNotContainsString(get_current_user().'.practice', json_encode($fleet), "nothing is named for the Mac's user");
    }

    public function test_several_candidates_refuse_naming_them(): void
    {
        $this->roster([...$this->fleets(), 'practice-req138' => ['slug' => 'practice-req138', 'domain' => 'req138.practice', 'practice' => true]]);
        $this->freshConfig();

        PracticeFleet::ensure();

        $why = PracticeFleet::refusal(config('simulation.fleets.practice'));
        $this->assertStringContainsString('several practice fleets (practice-req145, practice-req138)', (string) $why);
        $this->assertStringContainsString('SIMULATION_PRACTICE_INSTALL', (string) $why);
        $this->assertNull(config('simulation.fleets.practice.install'));
    }

    public function test_no_candidate_refuses(): void
    {
        $this->roster(['dashcore' => ['slug' => 'dashcore', 'practice' => false]]);
        $this->freshConfig();

        PracticeFleet::ensure();

        $this->assertStringContainsString('no practice fleet named practice-*', (string) PracticeFleet::refusal(config('simulation.fleets.practice')));
    }

    public function test_a_named_install_wins_and_takes_its_domain_from_the_roster_or_its_name(): void
    {
        $this->roster($this->fleets());
        $this->freshConfig();
        config(['simulation.fleets.practice.install' => 'starter-rehearsal']);

        PracticeFleet::ensure();
        $this->assertSame('starter-rehearsal.practice', config('simulation.fleets.practice.domain'));

        $this->roster([]);
        $this->freshConfig();
        config(['simulation.fleets.practice.install' => 'practice-req200']);

        PracticeFleet::ensure();
        $this->assertSame('req200.practice', config('simulation.fleets.practice.domain'));
        $this->assertSame('https://sales.req200.practice.test', config('simulation.fleets.practice.apps.sales.base_url'));
    }

    public function test_boot_reads_only_the_cache(): void
    {
        $export = Mockery::mock(FleetExport::class);
        $export->shouldNotReceive('fleets');
        $this->app->instance(FleetExport::class, $export);
        Cache::forget(PracticeFleet::CACHE_KEY);
        $this->freshConfig();

        PracticeFleet::apply();
        $this->assertStringContainsString('has not been read yet', (string) PracticeFleet::refusal(config('simulation.fleets.practice')));

        Cache::put(PracticeFleet::CACHE_KEY, ['practice-req145' => 'req145.practice'], 60);
        $this->freshConfig();
        PracticeFleet::apply();
        $this->assertSame('practice-req145', config('simulation.fleets.practice.install'));
    }

    public function test_a_journey_refuses_when_the_practice_fleet_is_unknown(): void
    {
        $this->roster([]);
        $this->freshConfig();
        config(['simulation.fleet' => 'practice']);

        $this->assertSame(1, Artisan::call('tests:journey', ['journey' => 'enquiry']));
        $this->assertStringContainsString('The practice fleet is not known', Artisan::output());
        $this->assertDatabaseHas('journey_runs', ['journey' => 'enquiry', 'fleet' => 'practice', 'outcome' => 'refused']);
    }
}
