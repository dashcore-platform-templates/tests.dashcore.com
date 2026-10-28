<?php

namespace Tests\Feature\Simulation;

use App\Services\FleetExport;
use App\Simulation\StandInPins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery;
use Tests\TestCase;

/**
 * A stand-in fleet is kept on the code its real fleet runs: each checkout is
 * moved to the release (or commit) fleets says that app runs live.
 */
class StandInPinsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    /** @var array<string, array{v1: string, main: string}> each checkout's commits */
    private array $commits = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = realpath(sys_get_temp_dir()).'/stand-ins-'.bin2hex(random_bytes(4));

        foreach (['sales.acme.practice', 'acme.practice', 'dcos.acme.practice', 'sales.dashcore.com'] as $checkout) {
            $this->repo($checkout);
        }

        config([
            'scanner.php' => PHP_BINARY,
            'simulation.fleets.practice.stands_in_for' => 'dashcore',
            'simulation.fleets.practice.apps' => [
                'website' => ['name' => 'Website', 'checkout' => "{$this->root}/acme.practice"],
                'sales' => ['name' => 'Sales', 'checkout' => "{$this->root}/sales.acme.practice"],
                'delivery' => ['name' => 'Delivery', 'checkout' => "{$this->root}/dcos.acme.practice"],
            ],
        ]);

        // Dashcore runs sales at its v1.0.0 release and the website at a
        // commit (its own apps deploy main); fleets says nothing of delivery.
        $this->fleetsSays([
            'sales' => ['release' => 'v1.0.0', 'commit' => null, 'local' => 'sales.dashcore.com'],
            'website' => ['release' => null, 'commit' => $this->commits['acme.practice']['v1'], 'local' => 'dashcore.com'],
            'delivery' => null,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function repo(string $checkout): void
    {
        $path = "{$this->root}/{$checkout}";
        File::ensureDirectoryExists($path);
        file_put_contents("{$path}/artisan", "<?php\n");
        $git = fn (string ...$args) => trim(Process::path($path)->run(['git', ...$args])->throw()->output());

        $git('init', '--quiet', '--initial-branch=main');
        file_put_contents("{$path}/CHANGES", "v1\n");
        $git('add', '.');
        $git('-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '--quiet', '-m', 'v1');
        $git('tag', 'v1.0.0');
        $v1 = $git('rev-parse', 'HEAD');
        file_put_contents("{$path}/CHANGES", "main\n", FILE_APPEND);
        $git('-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '--quiet', '-am', 'main');

        $this->commits[$checkout] = ['v1' => $v1, 'main' => $git('rev-parse', 'HEAD')];
    }

    private function headOf(string $checkout): string
    {
        return trim(Process::path("{$this->root}/{$checkout}")->run(['git', 'rev-parse', 'HEAD'])->output());
    }

    /** @param  array<string, array{release: ?string, commit: ?string, local: ?string}|null>  $apps */
    private function fleetsSays(array $apps): void
    {
        $export = Mockery::mock(FleetExport::class);

        foreach ($apps as $key => $live) {
            $export->shouldReceive('live')->with('dashcore', $key)->andReturn($live);
        }

        $this->app->instance(FleetExport::class, $export);
    }

    public function test_each_stand_in_is_moved_to_the_release_or_commit_its_fleet_runs_live(): void
    {
        $this->artisan('tests:pin-stand-ins', ['fleet' => 'practice'])
            ->expectsOutputToContain('moved from')
            ->assertSuccessful();

        $this->assertSame($this->commits['sales.acme.practice']['v1'], $this->headOf('sales.acme.practice'));
        $this->assertSame($this->commits['acme.practice']['v1'], $this->headOf('acme.practice'));
        // Nothing known about delivery: left where it was.
        $this->assertSame($this->commits['dcos.acme.practice']['main'], $this->headOf('dcos.acme.practice'));

        $this->assertSame([], app(StandInPins::class)->drift('practice'));

        $again = collect(app(StandInPins::class)->pin('practice'))->keyBy('app');
        $this->assertSame('current', $again['sales']['status']);
        $this->assertSame('current', $again['website']['status']);
        $this->assertSame('unknown', $again['delivery']['status']);
    }

    public function test_a_stand_in_off_its_live_release_is_drift(): void
    {
        $drift = app(StandInPins::class)->drift('practice');

        $this->assertSame(['website', 'sales'], array_column($drift, 'app'));
        $this->assertSame('v1.0.0', $drift[1]['live']);
        $this->assertSame($this->commits['sales.acme.practice']['v1'], $drift[1]['live_sha']);
        $this->assertSame($this->commits['sales.acme.practice']['main'], $drift[1]['actual_sha']);

        $this->assertSame(['sales'], array_column(app(StandInPins::class)->drift('practice', ['sales']), 'app'));
    }

    public function test_a_checkout_with_uncommitted_changes_or_that_is_no_stand_in_is_left_alone(): void
    {
        file_put_contents("{$this->root}/sales.acme.practice/CHANGES", "someone's work\n", FILE_APPEND);
        config(['simulation.fleets.practice.apps.website.checkout' => "{$this->root}/sales.dashcore.com"]);

        $results = collect(app(StandInPins::class)->pin('practice'))->keyBy('app');

        $this->assertSame('refused', $results['sales']['status']);
        $this->assertStringContainsString('uncommitted changes', $results['sales']['detail']);
        $this->assertSame($this->commits['sales.acme.practice']['main'], $this->headOf('sales.acme.practice'));

        $this->assertSame('refused', $results['website']['status']);
        $this->assertStringContainsString('is not a stand-in app', $results['website']['detail']);
        $this->assertSame($this->commits['sales.dashcore.com']['main'], $this->headOf('sales.dashcore.com'));

        $this->artisan('tests:pin-stand-ins', ['fleet' => 'practice'])->assertFailed();
    }

    public function test_the_json_report_names_each_app(): void
    {
        $this->artisan('tests:pin-stand-ins', ['--json' => true])->assertSuccessful();

        $this->artisan('tests:pin-stand-ins', ['fleet' => 'nowhere'])->expectsOutputToContain('No stand-in fleet "nowhere"')->assertFailed();
    }
}
