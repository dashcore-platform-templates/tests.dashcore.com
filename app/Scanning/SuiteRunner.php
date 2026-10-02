<?php

namespace App\Scanning;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Runs an app's suite in its workspace with the probe attached.
 *
 * The probe is loaded through auto_prepend_file and registered with
 * --extension, and Xdebug is loaded only into this process — nothing is
 * installed into the app or its composer.json. A failing suite is a result,
 * not an error: the scan only fails if the suite produced no evidence at all.
 */
class SuiteRunner
{
    /**
     * The whole suite, with coverage.
     *
     * @return array{framework: string, exit_code: ?int, output: string, probe: string, junit: string}
     */
    public function run(Workspace $workspace): array
    {
        $xdebug = (string) config('scanner.xdebug');

        if (! is_file($xdebug)) {
            throw new RuntimeException("Xdebug was not found at {$xdebug}. Coverage is how code surfaces are judged, so a scan without it would report everything as untested. Set SCANNER_XDEBUG.");
        }

        $framework = $this->framework($workspace);
        $probe = $workspace->runDir.'/probe.ndjson';
        $junit = $workspace->runDir.'/junit.xml';

        $result = $this->execute($workspace, $framework, $probe, ['--log-junit', $junit], (int) config('scanner.timeout'), $xdebug);

        $output = $result['output'];
        file_put_contents($workspace->runDir.'/output.log', $output);

        if (! is_file($probe) || filesize($probe) === 0) {
            throw new RuntimeException('The suite produced no test results (exit '.($result['exit_code'] ?? 'timeout').'). '.Str::limit(trim($output), 3000, '…', preserveWords: false));
        }

        return [
            'framework' => $framework,
            'exit_code' => $result['exit_code'],
            'output' => $output,
            'probe' => $probe,
            'junit' => $junit,
        ];
    }

    /**
     * Only the given test files (all of them when empty) — for checking a
     * mutant against the tests that reach it, or a build's new tests.
     * Coverage only when asked: it is what makes a run slow.
     *
     * @param  list<string>  $files  paths relative to the app
     * @return array{exit_code: ?int, timed_out: bool, probe: string, output: string}
     */
    public function runFiles(Workspace $workspace, array $files, string $name, int $timeout, bool $coverage = false): array
    {
        $probe = $workspace->runDir.'/'.$name.'.ndjson';
        $xdebug = $coverage ? (string) config('scanner.xdebug') : null;

        if ($xdebug !== null && ! is_file($xdebug)) {
            throw new RuntimeException("Xdebug was not found at {$xdebug}. Set SCANNER_XDEBUG.");
        }

        $result = $this->execute($workspace, $this->framework($workspace), $probe, $files, $timeout, $xdebug);
        file_put_contents($workspace->runDir.'/'.$name.'.log', $result['output']);

        return [
            'exit_code' => $result['exit_code'],
            'timed_out' => $result['timed_out'],
            'probe' => $probe,
            'output' => $result['output'],
        ];
    }

    /**
     * The PHP CLI binary. Under a web request PHP_BINARY is php-fpm, which
     * cannot run a script, so fall back to the `php` on PATH.
     */
    public function php(): string
    {
        $php = (string) config('scanner.php');

        return str_contains(basename($php), 'fpm') ? 'php' : $php;
    }

    private function framework(Workspace $workspace): string
    {
        $framework = is_file($workspace->path.'/vendor/bin/pest') ? 'pest' : 'phpunit';

        if (! is_file($workspace->path.'/vendor/bin/'.$framework)) {
            throw new RuntimeException('Neither Pest nor PHPUnit is installed in the app.');
        }

        return $framework;
    }

    /**
     * @param  list<string>  $arguments
     * @return array{exit_code: ?int, timed_out: bool, output: string}
     */
    private function execute(Workspace $workspace, string $framework, string $probe, array $arguments, int $timeout, ?string $xdebug): array
    {
        @unlink($probe);

        $ini = ['-d', 'memory_limit=-1'];

        if ($xdebug !== null) {
            array_push($ini, '-d', 'zend_extension='.$xdebug, '-d', 'xdebug.mode=coverage');
        }

        $environment = [
            ...$workspace->environment(),
            'DASHCORE_PROBE_OUT' => $probe,
            'DASHCORE_PROBE_ROOT' => $workspace->path,
        ];

        if ($xdebug === null) {
            $environment['XDEBUG_MODE'] = 'off';
        }

        $timedOut = false;

        try {
            $result = Process::path($workspace->path)
                ->timeout($timeout)
                ->env($environment)
                ->run([
                    $this->php(),
                    ...$ini,
                    '-d', 'auto_prepend_file='.base_path('probe/prepend.php'),
                    'vendor/bin/'.$framework,
                    '--extension', 'DashcoreTestsProbe\\Extension',
                    '--colors=never',
                    '--do-not-cache-result',
                    ...$arguments,
                ]);
        } catch (ProcessTimedOutException $e) {
            $timedOut = true;
            $result = $e->result;
        }

        return [
            'exit_code' => $timedOut ? null : $result->exitCode(),
            'timed_out' => $timedOut,
            'output' => $result->output()."\n".$result->errorOutput(),
        ];
    }
}
