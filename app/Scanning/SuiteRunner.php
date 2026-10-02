<?php

namespace App\Scanning;

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
     * @return array{framework: string, exit_code: ?int, output: string, probe: string, junit: string}
     */
    public function run(Workspace $workspace): array
    {
        $xdebug = (string) config('scanner.xdebug');

        if (! is_file($xdebug)) {
            throw new RuntimeException("Xdebug was not found at {$xdebug}. Coverage is how code surfaces are judged, so a scan without it would report everything as untested. Set SCANNER_XDEBUG.");
        }

        $framework = is_file($workspace->path.'/vendor/bin/pest') ? 'pest' : 'phpunit';

        if (! is_file($workspace->path.'/vendor/bin/'.$framework)) {
            throw new RuntimeException('Neither Pest nor PHPUnit is installed in the app.');
        }

        $probe = $workspace->runDir.'/probe.ndjson';
        $junit = $workspace->runDir.'/junit.xml';
        @unlink($probe);

        $result = Process::path($workspace->path)
            ->timeout((int) config('scanner.timeout'))
            ->env([
                ...$workspace->environment(),
                'DASHCORE_PROBE_OUT' => $probe,
                'DASHCORE_PROBE_ROOT' => $workspace->path,
            ])
            ->run([
                $this->php(),
                '-d', 'memory_limit=-1',
                '-d', 'zend_extension='.$xdebug,
                '-d', 'xdebug.mode=coverage',
                '-d', 'auto_prepend_file='.base_path('probe/prepend.php'),
                'vendor/bin/'.$framework,
                '--extension', 'DashcoreTestsProbe\\Extension',
                '--log-junit', $junit,
                '--colors=never',
                '--do-not-cache-result',
            ]);

        $output = $result->output()."\n".$result->errorOutput();
        file_put_contents($workspace->runDir.'/output.log', $output);

        if (! is_file($probe) || filesize($probe) === 0) {
            throw new RuntimeException('The suite produced no test results (exit '.($result->exitCode() ?? 'timeout').'). '.Str::limit(trim($output), 3000, '…', preserveWords: false));
        }

        return [
            'framework' => $framework,
            'exit_code' => $result->exitCode(),
            'output' => $output,
            'probe' => $probe,
            'junit' => $junit,
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
}
