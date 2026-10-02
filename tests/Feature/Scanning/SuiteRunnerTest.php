<?php

namespace Tests\Feature\Scanning;

use App\Models\FleetApp;
use App\Models\Scan;
use App\Scanning\SuiteRunner;
use App\Scanning\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class SuiteRunnerTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = realpath(sys_get_temp_dir()).'/suite-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir.'/vendor/bin');
        file_put_contents($dir.'/vendor/bin/pest', '');

        $app = FleetApp::factory()->create();
        $this->workspace = new Workspace($app, Scan::factory()->for($app)->create());
        $this->workspace->path = $dir;
        $this->workspace->runDir = $dir.'/_run';
        File::ensureDirectoryExists($this->workspace->runDir);

        config(['scanner.xdebug' => __FILE__]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace->path);

        parent::tearDown();
    }

    private function suite(bool $junit, int $exit): void
    {
        Process::fake(function (PendingProcess $process) use ($junit, $exit) {
            file_put_contents($this->workspace->runDir.'/probe.ndjson', json_encode(['id' => 'A::a', 'status' => 'passed', 'assertions' => 1])."\n");

            if ($junit) {
                file_put_contents($this->workspace->runDir.'/junit.xml', '<?xml version="1.0"?><testsuites><testsuite name="x"/></testsuites>');
            }

            return Process::result(output: 'Maximum execution time of 330 seconds exceeded', exitCode: $exit);
        });
    }

    public function test_a_suite_that_finished_is_a_result_even_with_failing_tests(): void
    {
        $this->suite(junit: true, exit: 1);

        $run = app(SuiteRunner::class)->run($this->workspace);

        $this->assertSame(1, $run['exit_code']);
        $this->assertSame('pest', $run['framework']);
    }

    public function test_a_suite_that_died_part_way_fails_the_scan_instead_of_understating_coverage(): void
    {
        $this->suite(junit: false, exit: 255);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The suite stopped before it finished (exit 255)');

        app(SuiteRunner::class)->run($this->workspace);
    }
}
