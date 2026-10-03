<?php

namespace Tests\Feature\Simulation;

use App\Enums\FindingKind;
use App\Enums\FindingStatus;
use App\Models\SimulationFinding;
use App\Models\SimulationRun;
use App\Simulation\Findings;
use App\Simulation\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonitorTest extends TestCase
{
    use RefreshDatabase;

    private string $checkout;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkout = sys_get_temp_dir().'/pg-app-'.bin2hex(random_bytes(4));
        $this->log = $this->checkout.'/storage/logs/laravel.log';
        File::ensureDirectoryExists(dirname($this->log));
        File::put($this->log, "[2026-09-21 07:58:27] local.ERROR: Something old. {\"exception\":\"x\"}\n");

        config([
            'simulation.targets.sales.checkout' => $this->checkout,
            'simulation.targets.sales.database' => null,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->checkout);

        parent::tearDown();
    }

    private function append(string $text): void
    {
        File::append($this->log, $text);
    }

    public function test_the_first_look_starts_from_now_so_old_errors_are_not_news(): void
    {
        app(Monitor::class)->check('sales');

        $this->assertSame(0, SimulationFinding::count());
    }

    public function test_new_errors_in_the_apps_log_become_findings_grouped_by_message(): void
    {
        $monitor = app(Monitor::class);
        $monitor->check('sales');

        $this->append(implode("\n", [
            '[2026-10-03 10:00:01] local.ERROR: Attempt to read property "name" on null (View: /app/resources/views/leads/show.blade.php) {"exception":"[object] ..."}',
            '#0 /app/vendor/laravel/framework/src/Illuminate/View/Engines/PhpEngine.php(58): ...',
            '[2026-10-03 10:00:02] local.INFO: Lead 281 captured.',
            '[2026-10-03 10:00:03] local.ERROR: Attempt to read property "name" on null (View: /app/resources/views/leads/show.blade.php) {"exception":"[object] ..."}',
            '[2026-10-03 10:00:04] local.CRITICAL: Deal 77 has no owner',
            '',
        ]));
        $monitor->check('sales');

        $findings = SimulationFinding::query()->orderBy('id')->get();
        $this->assertSame([FindingKind::LogError, FindingKind::LogError], $findings->pluck('kind')->all());
        $this->assertSame('Attempt to read property "name" on null (View: /app/resources/views/leads/show.blade.php)', $findings[0]->title);
        $this->assertSame(2, $findings[0]->occurrences);
        $this->assertStringStartsWith('Logged at 2026-10-03 10:00:03.', $findings[0]->detail);
        $this->assertSame('Deal # has no owner', $findings[1]->title);
        $this->assertNull($findings[0]->last_run_id);
    }

    public function test_an_entry_keeps_the_start_of_its_stack_trace(): void
    {
        $monitor = app(Monitor::class);
        $monitor->check('sales');

        $this->append("[2026-10-03 10:00:01] local.ERROR: Boom\n#0 /app/Foo.php(12): bar()\n#1 /app/Baz.php(3): foo()\n");
        $monitor->check('sales');

        $this->assertStringContainsString("#0 /app/Foo.php(12): bar()\n#1 /app/Baz.php(3): foo()", SimulationFinding::sole()->detail);
    }

    public function test_a_half_written_entry_waits_for_the_next_look(): void
    {
        $monitor = app(Monitor::class);
        $monitor->check('sales');

        $this->append('[2026-10-03 10:00:01] local.ERROR: Half');
        $monitor->check('sales');
        $this->assertSame(0, SimulationFinding::count());

        $this->append(" written\n");
        $monitor->check('sales');
        $this->assertSame('Half written', SimulationFinding::sole()->title);
    }

    public function test_a_rotated_log_starts_over_from_now(): void
    {
        $monitor = app(Monitor::class);
        $monitor->check('sales');

        File::put($this->log, "[2026-10-03 10:00:01] local.ERROR: After rotation\n");
        $monitor->check('sales');
        $this->assertSame(0, SimulationFinding::count());

        $this->append("[2026-10-03 10:00:05] local.ERROR: Next one\n");
        $monitor->check('sales');
        $this->assertSame('Next one', SimulationFinding::sole()->title);
    }

    public function test_new_failed_jobs_become_findings_and_the_queue_is_measured(): void
    {
        // The target's jobs and failed_jobs: this app has the same tables.
        config(['simulation.targets.sales.database' => config('database.connections.pgsql.database')]);
        // The monitor reads through its own connection; share this test's so
        // it sees the tables inside the test's transaction.
        DB::purge('simulation_sales');
        config(['database.connections.simulation_sales' => config('database.connections.pgsql')]);
        $this->app['db']->extend('simulation_sales', fn () => DB::connection());

        $failed = fn (int $lead) => DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ResearchLead']),
            'exception' => "RuntimeException: No research key for lead {$lead}\n#0 /app/Jobs/ResearchLead.php(40)",
            'failed_at' => now(),
        ]);

        $failed(1);
        DB::table('jobs')->insert(array_fill(0, 2, ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]));

        $monitor = app(Monitor::class);
        $monitor->check('sales');
        $this->assertSame(0, SimulationFinding::count());

        $failed(2);
        $failed(3);
        $monitor->check('sales');

        $finding = SimulationFinding::sole();
        $this->assertSame(FindingKind::FailedJob, $finding->kind);
        $this->assertSame('ResearchLead failed: RuntimeException: No research key for lead #', $finding->title);
        $this->assertSame(2, $finding->occurrences);

        $health = $monitor->health('sales');
        $this->assertSame([2, 3], [$health['waiting'], $health['failed']]);
        $this->assertGreaterThan(0, $health['database_bytes']);
    }

    public function test_a_server_side_finding_needs_a_long_quiet_spell_to_resolve(): void
    {
        config(['simulation.findings.resolve_after' => 1, 'simulation.findings.resolve_monitored_after' => 3]);
        app(Findings::class)->recordMonitored('sales', FindingKind::LogError, 'Boom', 'Boom', null);
        $finding = SimulationFinding::sole();

        foreach (range(1, 2) as $i) {
            app(Findings::class)->record(SimulationRun::factory()->create());
        }
        $this->assertSame([FindingStatus::Open, 2], [$finding->fresh()->status, $finding->fresh()->clean_runs]);

        app(Findings::class)->record(SimulationRun::factory()->create());
        $this->assertSame(FindingStatus::Resolved, $finding->fresh()->status);
    }
}
