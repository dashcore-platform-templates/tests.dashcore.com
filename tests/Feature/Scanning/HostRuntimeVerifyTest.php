<?php

namespace Tests\Feature\Scanning;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * `tests:host-runtime --verify` is Console's machine check that the scans
 * are back on: every worker the install lists is loaded in launchd — under
 * its own label or under the label fleets gave it — and the schedule's
 * switches are the ones asked for.
 */
class HostRuntimeVerifyTest extends TestCase
{
    private string $agents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agents = storage_path('framework/testing/launch-agents-'.getmypid());
        File::deleteDirectory($this->agents);
        File::ensureDirectoryExists($this->agents);

        config([
            'install.fleet' => 'dashcore',
            'install.host' => 'tests.dashcore.com',
            'scanner.workers' => 2,
            'scanner.launchd.launchctl' => '/bin/launchctl',
            'scanner.launchd.agents' => $this->agents,
            'scanner.nightly' => true,
            'scanner.nightly_at' => '01:00',
            'scanner.pull_requests.enabled' => true,
            'scanner.pull_requests.paused' => false,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->agents);

        parent::tearDown();
    }

    public function test_it_passes_when_every_worker_is_loaded_under_its_own_label(): void
    {
        $this->launchd(['com.dashcore.queue-tests', 'com.dashcore.queue-tests-2', 'com.dashcore.queue-tests-prs', 'com.dashcore.simulation-tests']);

        $this->assertSame(0, Artisan::call('tests:host-runtime', ['--verify' => true, '--nightly' => 'on', '--nightly-at' => '01:00', '--pr-scans' => 'on']));
        $this->assertStringContainsString('Every worker is loaded and the schedule switches match.', Artisan::output());
        Process::assertRan(fn ($process) => $process->command === ['/bin/launchctl', 'list']);
    }

    public function test_a_worker_counts_as_loaded_under_the_label_fleets_gave_it(): void
    {
        $this->agent('com.dashcore.tests-dashcore-scans', ['queue:work', 'scans', '--queue=scans,builds', '--sleep=10', '--timeout=16000']);
        $this->agent('com.dashcore.tests-dashcore-scans-2', ['queue:work', 'scans', '--queue=scans,builds', '--timeout=19800']);
        $this->agent('com.dashcore.tests-dashcore-prs', ['queue:work', 'scans', '--queue=prs']);
        $this->agent('com.dashcore.tests-dashcore-simulation', ['tests:simulate']);
        $this->launchd(['com.dashcore.tests-dashcore-scans', 'com.dashcore.tests-dashcore-scans-2', 'com.dashcore.tests-dashcore-prs', 'com.dashcore.tests-dashcore-simulation']);

        $this->assertSame(0, Artisan::call('tests:host-runtime', ['--verify' => true]));
    }

    public function test_it_fails_naming_each_worker_that_is_not_loaded(): void
    {
        // One scans agent cannot stand in for both scan workers; the PR
        // worker's plist is in place but launchd has not loaded it; the
        // simulation agent runs in another fleet's checkout.
        $this->agent('com.dashcore.tests-dashcore-scans', ['queue:work', 'scans', '--queue=scans,builds']);
        $this->agent('com.dashcore.tests-dashcore-prs', ['queue:work', 'scans', '--queue=prs']);
        $this->agent('com.dashcore.tests-acme-simulation', ['tests:simulate'], '/Users/someone/Herd/tests.acme.practice');
        $this->launchd(['com.dashcore.tests-dashcore-scans', 'com.dashcore.tests-acme-simulation']);

        $this->assertSame(1, Artisan::call('tests:host-runtime', ['--verify' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('com.dashcore.queue-tests-2 is not loaded in launchd', $output);
        $this->assertStringContainsString('com.dashcore.queue-tests-prs is not loaded in launchd', $output);
        $this->assertStringContainsString('com.dashcore.simulation-tests is not loaded in launchd', $output);
        $this->assertStringNotContainsString('com.dashcore.queue-tests is not', $output);
    }

    public function test_it_fails_when_a_schedule_switch_does_not_match_config(): void
    {
        config(['scanner.nightly' => false, 'scanner.nightly_at' => '01:20', 'scanner.pull_requests.enabled' => false]);
        $this->launchd(['com.dashcore.queue-tests', 'com.dashcore.queue-tests-2', 'com.dashcore.simulation-tests']);

        $this->assertSame(1, Artisan::call('tests:host-runtime', ['--verify' => true, '--nightly' => 'on', '--nightly-at' => '01:00', '--pr-scans' => 'on']));
        $output = Artisan::output();

        $this->assertStringContainsString('SCANNER_NIGHTLY is off, expected on', $output);
        $this->assertStringContainsString('SCANNER_NIGHTLY_AT is 01:20, expected 01:00', $output);
        $this->assertStringContainsString('SCANNER_PR_SCANS is off, expected on', $output);
    }

    public function test_pr_scans_switched_on_but_paused_lists_no_pr_worker_and_still_passes(): void
    {
        // The owner's call for REQ-145 slice 15: SCANNER_PR_SCANS=true, the
        // pause left alone — no PR worker is needed while it holds.
        config(['scanner.pull_requests.paused' => true]);
        $this->launchd(['com.dashcore.queue-tests', 'com.dashcore.queue-tests-2', 'com.dashcore.simulation-tests']);

        $this->assertSame(0, Artisan::call('tests:host-runtime', ['--verify' => true, '--pr-scans' => 'on']));
    }

    public function test_launchctl_failing_means_nothing_is_loaded(): void
    {
        Process::fake(['*' => Process::result('', 'Could not connect', 1)]);

        $this->assertSame(1, Artisan::call('tests:host-runtime', ['--verify' => true]));
        $this->assertStringContainsString('com.dashcore.queue-tests is not loaded in launchd', Artisan::output());
    }

    public function test_it_refuses_a_switch_value_it_cannot_read(): void
    {
        $this->launchd([]);

        $this->assertSame(2, Artisan::call('tests:host-runtime', ['--verify' => true, '--nightly' => 'yes']));
        $this->assertSame(2, Artisan::call('tests:host-runtime', ['--verify' => true, '--nightly-at' => '1am']));
    }

    /** @param  list<string>  $labels */
    private function launchd(array $labels): void
    {
        $lines = ["PID\tStatus\tLabel", "-\t0\tcom.apple.something"];

        foreach ($labels as $n => $label) {
            $lines[] = (100 + $n)."\t0\t{$label}";
        }

        Process::fake(['*launchctl*' => Process::result(implode("\n", $lines)."\n")]);
    }

    /** @param  list<string>  $arguments */
    private function agent(string $label, array $arguments, ?string $directory = null): void
    {
        $strings = implode('', array_map(fn (string $a) => "<string>{$a}</string>", ['/opt/php', 'artisan', ...$arguments]));
        $directory ??= base_path();

        File::put("{$this->agents}/{$label}.plist", <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
            <plist version="1.0">
            <dict>
                <key>Label</key>
                <string>{$label}</string>
                <key>ProgramArguments</key>
                <array>{$strings}</array>
                <key>WorkingDirectory</key>
                <string>{$directory}</string>
                <key>KeepAlive</key>
                <true/>
            </dict>
            </plist>
            XML);
        // A moved-aside copy must never count.
        File::put("{$this->agents}/{$label}.plist.paused-2026-10-31", 'ignored');
    }
}
