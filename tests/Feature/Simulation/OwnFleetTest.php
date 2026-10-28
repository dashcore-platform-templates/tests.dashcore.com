<?php

namespace Tests\Feature\Simulation;

use App\Services\FleetExport;
use App\Simulation\OwnFleet;
use App\Simulation\StandInGuard;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * Every fleet's tests walks only its own fleet's stand-ins: its Proving
 * Ground targets and customer journey fleets are its own, and a checkout
 * fleets lists under another fleet is never driven.
 */
class OwnFleetTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = realpath(sys_get_temp_dir()).'/own-fleet-'.bin2hex(random_bytes(4));

        foreach (['sales.travischapman.practice', 'sales.req138.practice', 'sales.fakecompany.com'] as $checkout) {
            File::ensureDirectoryExists("{$this->root}/{$checkout}");
        }

        config([
            'simulation.targets' => [
                'sales' => ['name' => 'Sales', 'checkout' => "{$this->root}/sales.fakecompany.com"],
                'req138-sales' => ['name' => 'Sales', 'fleet' => 'practice-req138', 'checkout' => "{$this->root}/sales.req138.practice"],
            ],
            'simulation.fleets' => [
                'practice' => ['name' => 'Practice fleet', 'install' => 'practice-travischapman', 'stands_in_for' => 'dashcore', 'apps' => ['sales' => ['checkout' => "{$this->root}/sales.travischapman.practice"]]],
                'req138' => ['name' => 'req138', 'install' => 'practice-req138', 'stands_in_for' => 'practice-req138', 'apps' => ['sales' => ['checkout' => "{$this->root}/sales.req138.practice"]]],
            ],
        ]);

        $export = Mockery::mock(FleetExport::class);
        $export->shouldReceive('fleets')->andReturn([
            'dashcore' => ['slug' => 'dashcore', 'apps' => [['key' => 'sales', 'local' => 'sales.dashcore.com']]],
            'practice-travischapman' => ['slug' => 'practice-travischapman', 'apps' => [['key' => 'sales', 'local' => 'sales.travischapman.practice']]],
            'practice-req138' => ['slug' => 'practice-req138', 'apps' => [['key' => 'sales', 'local' => 'sales.req138.practice']]],
        ]);
        $this->app->instance(FleetExport::class, $export);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function asInstall(string $fleet): void
    {
        config(['install.fleet' => $fleet]);
        OwnFleet::apply();
    }

    public function test_dashcores_tests_keeps_only_its_own_targets_and_stand_in_fleet(): void
    {
        $this->asInstall('dashcore');

        $this->assertSame(['sales'], array_keys(config('simulation.targets')));
        $this->assertSame(['practice'], array_keys(config('simulation.fleets')));
        $this->assertSame(['dashcore', 'practice-travischapman'], OwnFleet::slugs());
    }

    public function test_a_practice_fleets_tests_keeps_only_its_own(): void
    {
        $this->asInstall('practice-req138');

        $this->assertSame(['req138-sales'], array_keys(config('simulation.targets')));
        $this->assertSame(['req138'], array_keys(config('simulation.fleets')));
    }

    public function test_the_guard_refuses_a_stand_in_of_any_other_fleet(): void
    {
        $this->asInstall('dashcore');
        $guard = app(StandInGuard::class);

        $this->assertNull($guard->checkoutRefusal("{$this->root}/sales.travischapman.practice"));
        $this->assertNull($guard->checkoutRefusal("{$this->root}/sales.fakecompany.com"), 'the fake company is Dashcore\'s and no fleet lists it');
        $this->assertStringContainsString('sales.req138.practice is a stand-in of the practice-req138 fleet, not of dashcore\'s', (string) $guard->checkoutRefusal("{$this->root}/sales.req138.practice"));

        $this->asInstall('practice-req138');

        $this->assertNull($guard->checkoutRefusal("{$this->root}/sales.req138.practice"));
        $this->assertStringContainsString('is a stand-in of the practice-travischapman fleet', (string) $guard->checkoutRefusal("{$this->root}/sales.travischapman.practice"));
    }

    public function test_journeys_walk_this_installs_own_stand_in_fleet_and_no_other(): void
    {
        $this->asInstall('practice-req138');
        config(['simulation.fleet' => 'req138']);

        // Another install's stand-in fleet is not even known here.
        $this->artisan('tests:journey', ['journey' => 'enquiry', '--target' => 'practice'])
            ->expectsOutputToContain('No stand-in fleet "practice"')
            ->assertFailed();

        $this->artisan('tests:pin-stand-ins', ['fleet' => 'practice'])->assertFailed();
    }
}
