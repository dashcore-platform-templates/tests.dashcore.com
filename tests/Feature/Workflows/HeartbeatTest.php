<?php

namespace Tests\Feature\Workflows;

use App\Enums\SimulationStatus;
use App\Models\Scan;
use App\Models\User;
use App\Models\Workflow;
use App\Services\ScheduleHealth;
use App\Workflows\Heartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private string $keys;

    private string $shots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keys = sys_get_temp_dir().'/keys-'.bin2hex(random_bytes(4));
        $this->shots = sys_get_temp_dir().'/wf-'.bin2hex(random_bytes(4));
        config(['workflows.keys_checkout' => $this->keys, 'workflows.screenshots' => $this->shots, 'workflows.heartbeat_url' => null]);
        Http::fake();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->keys);
        File::deleteDirectory($this->shots);

        parent::tearDown();
    }

    private function keysHolds(string $url): void
    {
        File::ensureDirectoryExists($this->keys);
        File::put($this->keys.'/artisan', '<?php');
        Process::fake(['*keys:fleet*' => Process::result(output: json_encode(['tests.dashcore.com__WORKFLOWS_HEARTBEAT_URL__local' => $url]))]);
    }

    public function test_a_healthy_beat_pings_the_url_and_a_broken_one_pings_fail(): void
    {
        config(['workflows.heartbeat_url' => 'https://hc.example.test/ping/abc']);
        $heartbeat = app(Heartbeat::class);

        $heartbeat->beat(true, 'Tick: nothing due.');
        $heartbeat->beat(false, 'no browser');

        Http::assertSentInOrder([
            fn (Request $request) => $request->url() === 'https://hc.example.test/ping/abc' && $request->body() === 'Tick: nothing due.',
            fn (Request $request) => $request->url() === 'https://hc.example.test/ping/abc/fail' && $request->body() === 'no browser',
        ]);
        $this->assertTrue(Heartbeat::lastTick()->gt(now()->subMinute()));
    }

    public function test_the_url_comes_from_keys_and_keys_is_asked_at_most_hourly(): void
    {
        $this->keysHolds('https://hc.example.test/ping/from-keys');
        $heartbeat = app(Heartbeat::class);

        $heartbeat->beat(true, 'one');
        $heartbeat->beat(true, 'two');

        Process::assertRanTimes(fn (PendingProcess $process) => $process->command === [config('scanner.php'), 'artisan', 'keys:fleet', 'values']
            && $process->path === $this->keys
            && json_decode($process->input, true) === [['app' => 'tests.dashcore.com', 'environment' => 'local', 'variable' => 'WORKFLOWS_HEARTBEAT_URL']], 1);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.example.test/ping/from-keys');
    }

    public function test_with_no_url_anywhere_it_only_records_the_tick_and_does_not_ask_keys_every_minute(): void
    {
        $this->keysHolds('');
        $heartbeat = app(Heartbeat::class);

        $heartbeat->beat(true, 'one');
        $heartbeat->beat(true, 'two');

        Http::assertNothingSent();
        Process::assertRanTimes(fn (PendingProcess $process) => in_array('keys:fleet', $process->command, true), 1);
        $this->assertNotNull(Heartbeat::lastTick());
    }

    public function test_a_value_that_is_not_an_https_url_is_not_used(): void
    {
        $this->keysHolds('not a url');

        $this->assertNull(app(Heartbeat::class)->url());
    }

    public function test_every_scheduled_tick_beats_even_with_nothing_due(): void
    {
        config(['workflows.heartbeat_url' => 'https://hc.example.test/ping/abc']);

        $this->artisan('tests:workflows')->assertSuccessful();
        $this->artisan('tests:heartbeat')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.example.test/ping/abc' && $request->body() === "Workflows: nothing due.\nScans: 0 queued, 0 running.");
    }

    public function test_a_site_failing_its_workflow_is_not_a_broken_heartbeat(): void
    {
        config(['workflows.heartbeat_url' => 'https://hc.example.test/ping/abc']);
        Process::fake(['*' => Process::result(output: implode("\n", [
            json_encode(['event' => 'step', 'position' => 0, 'status' => 'failed', 'error' => 'net::ERR_NAME_NOT_RESOLVED']),
            json_encode(['event' => 'finished', 'status' => 'failed']),
        ]))]);
        Workflow::factory()->active()->create();

        $this->artisan('tests:workflows')->assertSuccessful();
        $this->artisan('tests:heartbeat')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.example.test/ping/abc' && str_starts_with($request->body(), 'Workflows: walked 1.'));
    }

    public function test_a_browser_that_never_starts_is_a_broken_heartbeat(): void
    {
        config(['workflows.heartbeat_url' => 'https://hc.example.test/ping/abc']);
        Process::fake(['*' => Process::result(errorOutput: "browserType.launch: Executable doesn't exist", exitCode: 1)]);
        $workflow = Workflow::factory()->active()->create();

        $this->artisan('tests:workflows')->assertSuccessful();
        $this->artisan('tests:heartbeat')->assertSuccessful();

        $this->assertSame(SimulationStatus::Failed, $workflow->runs()->first()->status);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.example.test/ping/abc/fail'
            && str_contains($request->body(), 'the browser never ran a step')
            && str_contains($request->body(), "Executable doesn't exist"));
    }

    public function test_each_install_reads_its_own_check_from_keys_under_its_own_host(): void
    {
        config(['install.host' => 'tests.acme.practice.dashcore.com', 'install.fleet' => 'acme']);
        File::ensureDirectoryExists($this->keys);
        File::put($this->keys.'/artisan', '<?php');
        Process::fake(['*keys:fleet*' => Process::result(output: json_encode([
            'tests.dashcore.com__WORKFLOWS_HEARTBEAT_URL__local' => 'https://hc.example.test/ping/dashcore',
            'tests.acme.practice.dashcore.com__WORKFLOWS_HEARTBEAT_URL__local' => 'https://hc.example.test/ping/acme',
        ]))]);

        $this->artisan('tests:heartbeat')->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process) => json_decode($process->input, true) === [['app' => 'tests.acme.practice.dashcore.com', 'environment' => 'local', 'variable' => 'WORKFLOWS_HEARTBEAT_URL']]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.example.test/ping/acme');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'dashcore'));
    }

    public function test_the_heartbeat_beats_on_every_tick_and_records_the_last_one(): void
    {
        config(['workflows.heartbeat_url' => 'https://hc.example.test/ping/abc']);

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'tests:heartbeat'));
        $this->assertNotNull($event, 'tests:heartbeat is scheduled');
        $this->assertSame('* * * * *', $event->expression);

        $this->artisan('tests:heartbeat')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.example.test/ping/abc' && $request->body() === 'Scans: 0 queued, 0 running.');
        $this->assertTrue(Heartbeat::lastTick()->gt(now()->subMinute()));
    }

    public function test_scans_nobody_takes_fail_the_heartbeat_and_a_live_worker_does_not(): void
    {
        config(['workflows.heartbeat_url' => 'https://hc.example.test/ping/abc', 'scanner.stalled_after_minutes' => 45]);
        Scan::factory()->create(['created_at' => now()->subHour()]);

        $this->artisan('tests:heartbeat')->assertSuccessful();
        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.example.test/ping/abc/fail' && str_contains($request->body(), 'nothing has taken one for 45 minutes'));

        // A worker that took a scan job — even one waiting for a host-wide slot — is alive.
        ScheduleHealth::scanWorkerSeen();
        Http::fake();
        $this->artisan('tests:heartbeat')->assertSuccessful();
        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.example.test/ping/abc' && $request->body() === 'Scans: 1 queued, 0 running.');
    }

    public function test_a_workflows_tick_that_stopped_finishing_fails_the_heartbeat(): void
    {
        config(['workflows.heartbeat_url' => 'https://hc.example.test/ping/abc']);
        $this->artisan('tests:workflows')->assertSuccessful();
        $this->travel(31)->minutes();

        $this->artisan('tests:heartbeat')->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/fail') && str_contains($request->body(), 'tests:workflows has not finished a tick'));
    }

    public function test_running_one_workflow_by_hand_is_not_a_tick(): void
    {
        config(['workflows.heartbeat_url' => 'https://hc.example.test/ping/abc']);
        Process::fake(['*' => Process::result(output: json_encode(['event' => 'finished', 'status' => 'failed']))]);

        $this->artisan('tests:workflows', ['workflow' => Workflow::factory()->create()->id])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull(Heartbeat::lastTick());
    }

    public function test_the_pages_warn_when_switched_on_workflows_are_not_being_walked(): void
    {
        $admin = User::factory()->admin()->create();
        $workflow = Workflow::factory()->create(['active' => true, 'next_run_at' => now()->addHour()]);

        $this->actingAs($admin)->get(route('workflows.index'))->assertSee('The schedule is not running')->assertSee('It has never run here.');
        $this->actingAs($admin)->get(route('workflows.show', $workflow))->assertSee('The schedule is not running');

        app(Heartbeat::class)->beat(true, 'tick');
        $this->actingAs($admin)->get(route('workflows.index'))->assertDontSee('The schedule is not running');

        $this->travel(10)->minutes();
        $this->actingAs($admin)->get(route('workflows.index'))->assertSee('The schedule is not running');

        $workflow->update(['active' => false]);
        $this->actingAs($admin)->get(route('workflows.index'))->assertDontSee('The schedule is not running');
    }
}
