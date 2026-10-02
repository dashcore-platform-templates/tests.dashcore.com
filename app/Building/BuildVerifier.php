<?php

namespace App\Building;

use App\Enums\MutationResult;
use App\Enums\ScanStatus;
use App\Enums\SurfaceStatus;
use App\Enums\TestOutcome;
use App\Models\Build;
use App\Models\Surface;
use App\Scanning\Analyzer;
use App\Scanning\Evidence\ProbeReader;
use App\Scanning\Evidence\TestRecord;
use App\Scanning\Inventory\DiscoveredSurface;
use App\Scanning\Inventory\Inventory;
use App\Scanning\Mutation\MutationTester;
use App\Scanning\SuiteRunner;
use App\Scanning\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Decides, without taking the agent's word for anything, whether a build's
 * tests are good enough to propose.
 *
 * In order: anything changed outside tests/ and database/factories/ is put
 * back; the new tests must exist, pass and assert; each target is judged by
 * the same probe and analyser a scan uses, then mutation-checked; and the
 * whole suite must have no failure the base scan did not have. A build is
 * accepted when at least one target is proven and nothing is broken.
 */
class BuildVerifier
{
    private const ALLOWED = ['tests/', 'database/factories/'];

    public function __construct(
        private readonly SuiteRunner $runner,
        private readonly ProbeReader $reader,
        private readonly Inventory $inventory,
        private readonly Analyzer $analyzer,
        private readonly MutationTester $mutations,
    ) {}

    /**
     * @param  Collection<int, Surface>  $targets
     * @param  array<string, true>  $baseline  tests already failing on the base commit
     * @return array{accepted: bool, reasons: list<string>, notes: list<string>, files: list<string>, reverted: list<string>, tests: int, targets: array<int, array{label: string, status: string, mutation: ?string, proven: bool, tests: list<string>}>}
     */
    public function verify(Build $build, Workspace $workspace, Collection $targets, array $baseline = []): array
    {
        $reasons = [];
        [$files, $reverted] = $this->changedFiles($workspace);
        $testFiles = array_values(array_filter($files, fn (string $f) => str_starts_with($f, 'tests/') && str_ends_with($f, '.php')));

        $report = ['accepted' => false, 'reasons' => [], 'notes' => [], 'files' => $files, 'reverted' => $reverted, 'tests' => 0, 'targets' => []];

        if ($testFiles === []) {
            $report['reasons'] = ['The agent wrote no test files.'];

            return $report;
        }

        // The new tests on their own, with coverage, so each target can be judged.
        $run = $this->runner->runFiles($workspace, $testFiles, 'build-tests', (int) config('scanner.timeout'), coverage: true);
        $tests = $this->reader->read($run['probe']);
        $report['tests'] = count($tests);

        if ($tests === []) {
            $report['reasons'] = ['The new tests did not run: '.mb_substr(trim($run['output']), -1500)];

            return $report;
        }

        foreach ($tests as $test) {
            if ($test->outcome !== TestOutcome::Passed) {
                $reasons[] = "A new test does not pass ({$test->outcome->value}): {$test->name}".($test->message ? ' — '.mb_substr($test->message, 0, 300) : '');
            } elseif ($test->assertions === 0) {
                $reasons[] = "A new test asserts nothing: {$test->name}";
            }
        }

        $report['targets'] = $this->judge($workspace, $targets, $tests);
        $proven = count(array_filter($report['targets'], fn (array $t) => $t['proven']));

        if ($proven === 0) {
            $reasons[] = 'None of the targets is proven by the new tests.';
        }

        // The whole suite, to catch new tests that break or lean on others.
        if ($reasons === []) {
            $suite = $this->suiteRegressions($build, $workspace, $baseline);
            $reasons = $suite['reasons'];
            $report['notes'] = $suite['notes'];
        }

        $report['accepted'] = $reasons === [];
        $report['reasons'] = $reasons;

        return $report;
    }

    /**
     * Paths the agent changed, with anything outside the allowed directories
     * put back. Files the workspace itself rewrote are not the agent's.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function changedFiles(Workspace $workspace): array
    {
        $status = Process::path($workspace->path)->run(['git', 'status', '--porcelain', '--untracked-files=all', '-z'])->output();
        $kept = [];
        $reverted = [];

        $entries = array_values(array_filter(explode("\0", $status), fn (string $e) => $e !== ''));

        for ($i = 0; $i < count($entries); $i++) {
            $code = substr($entries[$i], 0, 2);
            $path = substr($entries[$i], 3);

            // A rename carries its old path as the next field; the old path is gone.
            if ($code[0] === 'R' || $code[0] === 'C') {
                $i++;
            }

            if (isset($workspace->rewritten[$path]) && is_file($workspace->path.'/'.$path) && sha1_file($workspace->path.'/'.$path) === $workspace->rewritten[$path]) {
                continue;
            }

            if ($this->allowed($path)) {
                $kept[] = $path;

                continue;
            }

            if ($code === '??') {
                File::delete($workspace->path.'/'.$path);
            } else {
                Process::path($workspace->path)->run(['git', 'checkout', '--', $path]);
            }

            $reverted[] = $path;
        }

        sort($kept);

        return [$kept, $reverted];
    }

    private function allowed(string $path): bool
    {
        foreach (self::ALLOWED as $prefix) {
            if (str_starts_with($path, $prefix) && ! str_contains($path, '..')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each target as a scan would see it with only the new tests, then
     * mutation-checked where it has a body.
     *
     * @param  Collection<int, Surface>  $targets
     * @param  list<TestRecord>  $tests
     * @return array<int, array{label: string, status: string, mutation: ?string, proven: bool, tests: list<string>}>
     */
    private function judge(Workspace $workspace, Collection $targets, array $tests): array
    {
        $found = collect($this->inventory->discover($workspace->path, $this->runner->php(), $workspace->environment()))
            ->keyBy(fn (DiscoveredSurface $s) => $s->key());

        $byId = [];

        foreach ($tests as $test) {
            $byId[$test->id] = $test;
        }

        $verdicts = [];

        foreach ($targets as $target) {
            $surface = $found->get($target->kind->value.'|'.$target->identifier);

            if ($surface === null) {
                $verdicts[$target->id] = ['label' => $target->label, 'status' => 'gone', 'mutation' => null, 'proven' => false, 'tests' => []];

                continue;
            }

            $result = $this->analyzer->analyse([$surface], $tests)->results[$surface->key()];
            $mutation = null;

            if ($result['status'] === SurfaceStatus::Tested && $surface->hasLines() && str_ends_with((string) $surface->file, '.php')) {
                $proving = array_values(array_filter(array_map(fn (string $id) => $byId[$id] ?? null, $result['proving'])));
                $mutation = $this->mutations->test($workspace, $surface, $proving)['result'];
            }

            $verdicts[$target->id] = [
                'label' => $target->label,
                'status' => $result['status']->value,
                'mutation' => $mutation?->value,
                'proven' => $result['status'] === SurfaceStatus::Tested && $mutation !== MutationResult::Survived,
                'tests' => array_map(fn (string $id) => $byId[$id]->name ?? $id, $result['tests']),
            ];
        }

        return $verdicts;
    }

    /**
     * The tests that already fail on the unchanged base commit. A scan of that
     * exact commit says so; otherwise the suite is run now, before anything
     * is changed — a failure that was already there is not the build's.
     *
     * @return array<string, true> failing test ids
     */
    public function baselineFailures(Build $build, Workspace $workspace): array
    {
        $scan = $build->scan;

        if ($scan !== null && $scan->status === ScanStatus::Completed && $scan->commit_sha === $build->base_sha) {
            return $scan->tests()
                ->whereIn('outcome', [TestOutcome::Failed, TestOutcome::Errored])
                ->pluck('test_id')
                ->flip()
                ->map(fn () => true)
                ->all();
        }

        $run = $this->runner->runFiles($workspace, [], 'base-suite', (int) config('scanner.timeout'));
        $records = $this->reader->read($run['probe']);

        if ($records === []) {
            throw new RuntimeException('The suite does not run on the base commit, so nothing built on it can be verified: '.mb_substr(trim($run['output']), -1500));
        }

        $failing = [];

        foreach ($records as $record) {
            if ($record->outcome->isFailure()) {
                $failing[$record->id] = true;
            }
        }

        return $failing;
    }

    /**
     * Failures in the full suite that the base did not have. Each is run once
     * more on its own before it counts: one that passes then is flaky, noted
     * rather than blamed on the change.
     *
     * @param  array<string, true>  $baseline
     * @return array{reasons: list<string>, notes: list<string>}
     */
    public function suiteRegressions(Build $build, Workspace $workspace, array $baseline): array
    {
        $run = $this->runner->runFiles($workspace, [], 'build-suite', (int) config('scanner.timeout'));
        $records = $this->reader->read($run['probe']);

        if ($records === []) {
            return ['reasons' => ['The full suite did not run with the change in it: '.mb_substr(trim($run['output']), -1500)], 'notes' => []];
        }

        $new = array_values(array_filter($records, fn (TestRecord $t) => $t->outcome->isFailure() && ! isset($baseline[$t->id])));

        if ($new === []) {
            return ['reasons' => [], 'notes' => []];
        }

        $files = array_values(array_unique(array_filter(array_map(fn (TestRecord $t) => $t->file, $new))));
        $again = [];

        foreach ($this->reader->read($this->runner->runFiles($workspace, $files, 'build-recheck', (int) config('scanner.timeout'))['probe']) as $record) {
            $again[$record->id] = $record->outcome;
        }

        $reasons = [];
        $notes = [];

        foreach ($new as $test) {
            $retry = $again[$test->id] ?? null;

            if ($retry !== null && ! $retry->isFailure()) {
                $notes[] = "Flaky: {$test->name} ({$test->file}) failed in the full suite and passed when run again.";

                continue;
            }

            $reasons[] = "The full suite has a new failure: {$test->name} ({$test->file})".($test->message ? ' — '.mb_substr($test->message, 0, 300) : '');
        }

        return ['reasons' => array_slice($reasons, 0, 10), 'notes' => $notes];
    }
}
