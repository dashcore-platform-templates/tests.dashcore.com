<?php

namespace App\Simulation;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Walks a script through the Proving Ground's browser (simulator/run.mjs)
 * and hands back what happened, step by step as it happens — without the
 * Proving Ground's run records, findings or coverage, which belong to its
 * never-ending simulation of one fake company. A customer journey is a
 * check someone runs and reads, so the console is where it reports.
 */
class JourneyWalker
{
    /**
     * @param  array<string, string>  $baseUrls  each app's address, by app
     * @param  callable(array{position: int, label: string, do: string, on: ?string, status: string, ms: ?int, error: ?string, url: ?string, screenshot: ?string}): void  $onStep
     */
    public function walk(Script $script, array $baseUrls, string $shotsDir, callable $onStep): JourneyResult
    {
        File::ensureDirectoryExists($shotsDir);

        $steps = $script->steps();
        $result = new JourneyResult($shotsDir);
        $buffer = '';

        $handle = function (string $line) use (&$result, $steps, $onStep) {
            $event = json_decode(trim($line), true);

            if (! is_array($event)) {
                return;
            }

            switch ($event['event'] ?? null) {
                case 'remember':
                    $result->remembered[(string) ($event['name'] ?? '')] = (string) ($event['value'] ?? '');

                    return;

                case 'console':
                    if (($event['level'] ?? null) === 'http') {
                        $result->serverErrors[] = Str::limit((string) ($event['text'] ?? ''), 300);
                    }

                    return;

                case 'error':
                    $result->error ??= Str::limit((string) ($event['text'] ?? 'The browser failed.'), 2000);

                    return;

                case 'finished':
                    $result->finished = ($event['status'] ?? null) === 'passed';

                    return;

                case 'step':
                    if (($event['status'] ?? null) === 'running') {
                        return;
                    }

                    $position = (int) ($event['position'] ?? -1);
                    $step = $steps[$position] ?? ['label' => 'Unknown step', 'do' => '?'];
                    $outcome = [
                        'position' => $position,
                        'label' => $result->interpolate($step['label']),
                        'do' => $step['do'],
                        'on' => $step['on'] ?? null,
                        'status' => (string) ($event['status'] ?? 'failed'),
                        'ms' => isset($event['ms']) ? (int) $event['ms'] : null,
                        'error' => $event['error'] ?? null,
                        'url' => $event['url'] ?? null,
                        'screenshot' => $event['screenshot'] ?? null,
                    ];
                    $result->steps[] = $outcome;
                    $onStep($outcome);
            }
        };

        $browser = Process::path(base_path('simulator'))
            ->timeout((int) config('simulation.run_timeout'))
            ->input(json_encode([
                'base_url' => $baseUrls[$script->apps()[0] ?? array_key_first($baseUrls)] ?? null,
                'base_urls' => $baseUrls,
                'shots_dir' => $shotsDir,
                'step_timeout_ms' => (int) config('simulation.step_timeout_ms'),
                'steps' => $steps,
            ], JSON_THROW_ON_ERROR))
            ->start([config('simulation.node'), config('simulation.runner')], function (string $type, string $output) use (&$buffer, $handle) {
                if ($type === 'err') {
                    return;
                }

                $buffer .= $output;

                while (($newline = strpos($buffer, "\n")) !== false) {
                    $handle(substr($buffer, 0, $newline));
                    $buffer = substr($buffer, $newline + 1);
                }
            });

        $process = null;

        try {
            $process = $browser->wait();
        } catch (ProcessTimedOutException) {
            $result->error = 'The journey took longer than '.config('simulation.run_timeout').' seconds and was stopped.';
        }

        if ($buffer !== '') {
            $handle($buffer);
        }

        if ($process !== null && ! $process->successful() && $result->error === null && $result->finished === null) {
            $result->error = Str::limit(trim($process->errorOutput()) ?: 'The browser runner exited with code '.$process->exitCode().'.', 2000);
        }

        $result->total = count($steps);

        return $result;
    }
}
