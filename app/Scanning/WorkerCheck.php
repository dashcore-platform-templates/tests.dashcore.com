<?php

namespace App\Scanning;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Whether every worker `tests:host-runtime` lists is loaded in launchd, and
 * whether the schedule's switches are the ones a caller expects — the
 * machine check behind `tests:host-runtime --verify`, so Console can prove
 * a "switch the scans back on" step worked instead of a person looking.
 *
 * A worker is loaded when launchd has its label, or — because fleets
 * installs the plists under its own labels — when a loaded agent's plist
 * runs the same artisan command (queue:work and its queues, or
 * tests:simulate) in this checkout. Each agent stands in for one worker
 * only, so two scan workers need two agents.
 */
class WorkerCheck
{
    public function __construct(private HostRuntime $runtime) {}

    /**
     * Each listed worker's label that launchd has nothing loaded for.
     *
     * @return list<string>
     */
    public function missingWorkers(): array
    {
        $loaded = $this->loadedLabels();
        $agents = array_filter($this->agents(), fn (array $agent) => in_array($agent['label'], $loaded, true));
        $workers = $this->runtime->describe()['launchd'];
        $missing = [];

        // Exact labels first, so a stand-in agent is never spent on a worker
        // that is loaded under its own name.
        foreach ($workers as $i => $worker) {
            if (in_array($worker['label'], $loaded, true)) {
                unset($workers[$i]);
                $agents = array_filter($agents, fn (array $agent) => $agent['label'] !== $worker['label']);
            }
        }

        foreach ($workers as $worker) {
            $match = collect($agents)->search(fn (array $agent) => $agent['directory'] === rtrim($worker['working_directory'], '/')
                && $agent['command'] === self::command($worker['program_arguments']));

            if ($match === false) {
                $missing[] = $worker['label'];

                continue;
            }

            unset($agents[$match]);
        }

        return $missing;
    }

    /**
     * How the schedule's switches differ from what was expected, one line
     * each. Keys: nightly (bool), nightly_at (HH:MM), pull_request_scans
     * (bool: the SCANNER_PR_SCANS switch itself).
     *
     * @param  array{nightly?: bool, nightly_at?: string, pull_request_scans?: bool}  $expected
     * @return list<string>
     */
    public function switchMismatches(array $expected): array
    {
        $actual = [
            'nightly' => (bool) config('scanner.nightly'),
            'nightly_at' => (string) config('scanner.nightly_at'),
            'pull_request_scans' => (bool) config('scanner.pull_requests.enabled'),
        ];
        $names = ['nightly' => 'SCANNER_NIGHTLY', 'nightly_at' => 'SCANNER_NIGHTLY_AT', 'pull_request_scans' => 'SCANNER_PR_SCANS'];
        $mismatches = [];

        foreach ($expected as $key => $want) {
            if ($actual[$key] !== $want) {
                $mismatches[] = "{$names[$key]} is ".self::show($actual[$key]).', expected '.self::show($want);
            }
        }

        return $mismatches;
    }

    /**
     * The labels launchd has loaded in this user's session.
     *
     * @return list<string>
     */
    private function loadedLabels(): array
    {
        $result = Process::timeout(30)->run([(string) config('scanner.launchd.launchctl'), 'list']);

        if (! $result->successful()) {
            return [];
        }

        $labels = [];

        foreach (preg_split('/\R/', $result->output()) ?: [] as $line) {
            $columns = preg_split('/\s+/', trim($line));

            if (is_array($columns) && count($columns) >= 3 && $columns[2] !== 'Label') {
                $labels[] = $columns[2];
            }
        }

        return $labels;
    }

    /**
     * Every launch agent plist in the folder: its label, working directory
     * and the artisan command it runs.
     *
     * @return list<array{label: string, directory: string, command: string}>
     */
    private function agents(): array
    {
        $folder = (string) config('scanner.launchd.agents');

        if ($folder === '' || ! File::isDirectory($folder)) {
            return [];
        }

        $agents = [];

        foreach (File::glob(rtrim($folder, '/').'/*.plist') as $path) {
            $plist = self::readPlist($path);

            if ($plist === null || ! is_string($plist['Label'] ?? null) || ! is_array($plist['ProgramArguments'] ?? null)) {
                continue;
            }

            $agents[] = [
                'label' => $plist['Label'],
                'directory' => rtrim((string) ($plist['WorkingDirectory'] ?? ''), '/'),
                'command' => self::command(array_values(array_map('strval', $plist['ProgramArguments']))),
            ];
        }

        return $agents;
    }

    /**
     * What a worker does, without its tuning: the artisan command, its
     * arguments and its --queue, never --timeout or --sleep.
     *
     * @param  list<string>  $arguments
     */
    private static function command(array $arguments): string
    {
        $artisan = array_search('artisan', $arguments, true);

        if ($artisan === false) {
            return '';
        }

        $kept = array_filter(
            array_slice($arguments, $artisan + 1),
            fn (string $argument) => ! str_starts_with($argument, '--') || str_starts_with($argument, '--queue='),
        );

        return implode(' ', $kept);
    }

    /**
     * The top-level dict of an XML plist, keys to strings or lists of strings.
     *
     * @return array<string, string|list<string>>|null
     */
    private static function readPlist(string $path): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) File::get($path), options: LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if ($xml === false || ! isset($xml->dict)) {
            return null;
        }

        $values = [];
        $key = null;

        foreach ($xml->dict->children() as $node) {
            $name = $node->getName();

            if ($name === 'key') {
                $key = (string) $node;

                continue;
            }

            if ($key === null) {
                continue;
            }

            $values[$key] = $name === 'array'
                ? array_map('strval', iterator_to_array($node->children(), false))
                : (string) $node;
            $key = null;
        }

        return $values;
    }

    private static function show(bool|string $value): string
    {
        return is_bool($value) ? ($value ? 'on' : 'off') : $value;
    }
}
