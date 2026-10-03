<?php

namespace App\Scanning;

use App\Building\TestkitInstaller;
use App\Enums\MutationResult;
use App\Enums\ScanStatus;
use App\Enums\SurfaceChange;
use App\Enums\SurfaceStatus;
use App\Enums\TestOutcome;
use App\Library\Library;
use App\Models\Scan;
use App\Models\Surface;
use App\Scanning\Evidence\ProbeReader;
use App\Scanning\Evidence\TestRecord;
use App\Scanning\Inventory\Inventory;
use App\Scanning\Mutation\MutationTester;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * One scan, end to end: check out, run the suite under the probe, list the
 * app's surfaces, decide which are tested, and record it.
 */
class Scanner
{
    public function __construct(
        private readonly CheckoutLocator $checkouts,
        private readonly SuiteRunner $runner,
        private readonly Inventory $inventory,
        private readonly ProbeReader $reader,
        private readonly Analyzer $analyzer,
        private readonly MutationTester $mutations,
    ) {}

    public function run(Scan $scan): Scan
    {
        $app = $scan->fleetApp;

        $scan->update(['status' => ScanStatus::Preparing, 'started_at' => now(), 'error' => null]);

        $workspace = null;

        try {
            if (! $app->isScannable() || ! $this->checkouts->contains((string) $app->local_path)) {
                throw new RuntimeException("There is no checkout of {$app->host} under the scanner root on this machine.");
            }

            $workspace = app(Workspace::class, ['app' => $app, 'for' => $scan]);
            $base = null;

            if ($scan->isPullRequest()) {
                $revision = $workspace->resolvePullRequest((int) $scan->pr_number);
                $base = $this->baseFor($scan, $revision['base'], $workspace);

                if ($base === null) {
                    return $this->defer($scan, $revision['base']);
                }

                $scan->update(['base_scan_id' => $base->id]);
            } else {
                $revision = $workspace->resolveRevision();
            }

            $scan->update([
                'ref' => $revision['ref'],
                'commit_sha' => $revision['sha'],
                'commit_subject' => Str::limit($revision['subject'], 250),
                'test_database' => $workspace->database,
            ]);

            $workspace->prepare($revision['sha']);

            $scan->update(['status' => ScanStatus::Running]);
            $run = $this->runner->run($workspace);

            $scan->update([
                'status' => ScanStatus::Analysing,
                'framework' => $run['framework'],
                'exit_code' => $run['exit_code'],
                'testkit_version' => app(TestkitInstaller::class)->installedVersion($workspace->path),
            ]);

            $surfaces = $this->inventory->discover($workspace->path, $this->runner->php(), $workspace->environment());
            $tests = $this->reader->read($run['probe']);

            if ($this->reader->skipped > 0) {
                $workspace->notes[] = "{$this->reader->skipped} probe line(s) were incomplete and skipped — the suite may have died mid-test.";
            }

            $tests = $this->recheckFailures($workspace, $tests);

            $analysis = $this->analyzer->analyse($surfaces, $tests);

            if ($base !== null) {
                // Known mutation verdicts still apply (by unchanged code), but
                // a PR waits for no new mutants.
                [$analysis] = $this->mutate($scan, $workspace, $analysis, $tests, budget: 0);
                $this->recordPullRequest($scan, $base, $analysis, $tests, $run['output'], [...$workspace->notes, ...$this->inventory->notes]);
            } else {
                $scan->update(['status' => ScanStatus::Mutating]);
                [$analysis, $mutations] = $this->mutate($scan, $workspace, $analysis, $tests);

                $this->record($scan, $analysis, $mutations, $tests, $run['output'], [...$workspace->notes, ...$this->inventory->notes]);

                // The library keeps the default branch's tests themselves, at
                // this commit (a PR scan leaves it alone). It must never cost a
                // scan its verdict, so a failure here is only noted.
                try {
                    app(Library::class)->captureScan($scan->refresh());
                } catch (Throwable $e) {
                    report($e);
                    $scan->update(['notes' => [...($scan->notes ?? []), 'The test library could not be updated: '.Str::limit($e->getMessage(), 300)]]);
                }
            }
        } catch (Throwable $e) {
            report($e);

            $scan->update([
                'status' => ScanStatus::Failed,
                'error' => Str::limit($e->getMessage(), 5000),
                'notes' => $workspace?->notes ?: null,
                'finished_at' => now(),
            ]);
        } finally {
            $workspace?->cleanup();

            if ($workspace !== null) {
                $this->pruneRuns($workspace);
            }
        }

        return $scan->refresh();
    }

    /**
     * Coverage makes a suite several times slower, so a test that asserts how
     * long something takes can fail only because it was measured. Each failing
     * test's file is run once more without coverage; a test that passes then
     * is recorded as passing — what it executed under coverage still stands as
     * evidence — and the scan says which tests those were.
     *
     * @param  list<TestRecord>  $tests
     * @return list<TestRecord>
     */
    private function recheckFailures(Workspace $workspace, array $tests): array
    {
        $failing = array_filter($tests, fn (TestRecord $t) => $t->outcome->isFailure());
        $files = array_values(array_unique(array_filter(array_map(fn (TestRecord $t) => $t->file, $failing))));

        if ($files === []) {
            return $tests;
        }

        $again = [];

        foreach ($this->reader->read($this->runner->runFiles($workspace, $files, 'recheck', (int) config('scanner.timeout'))['probe']) as $record) {
            $again[$record->id] = $record;
        }

        $passed = [];

        $tests = array_map(function (TestRecord $test) use ($again, &$passed) {
            $retry = $again[$test->id] ?? null;

            if (! $test->outcome->isFailure() || $retry === null || $retry->outcome !== TestOutcome::Passed) {
                return $test;
            }

            $passed[] = $test->name;

            return new TestRecord($test->id, $test->file, $test->name, TestOutcome::Passed, $retry->assertions, $test->durationMs, null, $test->keys, $test->lines);
        }, $tests);

        if ($passed !== []) {
            $workspace->notes[] = count($passed).' test(s) failed only under coverage and passed when run again without it (timing or flakiness): '.implode('; ', array_slice($passed, 0, 10));
        }

        return $tests;
    }

    /**
     * Check that the tests proving each method would notice it breaking, within
     * the scan's budget. Verdicts are kept while a method's code is unchanged:
     * survivors are re-checked first (their tests may have improved), then
     * methods never checked or changed since, then kills older than the
     * recheck window. A survivor carried over without a re-check stays Weak.
     *
     * @param  list<TestRecord>  $tests
     * @return array{0: Analysis, 1: array<string, array{result: MutationResult, fresh: bool, hash: string}>}
     */
    private function mutate(Scan $scan, Workspace $workspace, Analysis $analysis, array $tests, ?int $budget = null): array
    {
        $budget = max(0, $budget ?? (int) config('scanner.mutation.budget'));
        $recheckBefore = now()->subDays(max(1, (int) config('scanner.mutation.recheck_days')));
        $index = [];

        foreach ($tests as $test) {
            $index[$test->id] = $test;
        }

        $known = $scan->fleetApp->surfaces()->whereNotNull('mutation')->get()
            ->keyBy(fn (Surface $s) => $s->kind->value.'|'.$s->identifier);

        $verdicts = [];
        $queue = [];

        foreach ($analysis->results as $key => $result) {
            $surface = $result['surface'];

            if ($result['status'] !== SurfaceStatus::Tested || ! $surface->hasLines() || $surface->hash === null || ! str_ends_with((string) $surface->file, '.php')) {
                continue;
            }

            $prior = $known->get($key);
            $same = $prior !== null && $prior->mutation_hash === $surface->hash;
            $recent = $same && $prior->mutation_checked_at?->isAfter($recheckBefore);

            $priority = match (true) {
                $same && $prior->mutation === MutationResult::Survived->value => 0,
                ! $same => 1,
                $recent => null,
                default => 2,
            };

            if ($same) {
                $verdicts[$key] = ['result' => MutationResult::from($prior->mutation), 'fresh' => false, 'hash' => $surface->hash];
            }

            if ($priority !== null) {
                $queue[] = [$priority, $key];
            }
        }

        usort($queue, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        foreach (array_slice($queue, 0, $budget) as [, $key]) {
            $result = $analysis->results[$key];
            $proving = array_values(array_filter(array_map(fn (string $id) => $index[$id] ?? null, $result['proving'])));

            $outcome = $this->mutations->test($workspace, $result['surface'], $proving);

            if ($outcome['result'] === MutationResult::Error && isset($verdicts[$key])) {
                continue;
            }

            $verdicts[$key] = ['result' => $outcome['result'], 'fresh' => true, 'hash' => (string) $result['surface']->hash];
        }

        foreach ($verdicts as $key => $verdict) {
            if ($verdict['result'] === MutationResult::Survived) {
                $analysis = $analysis->withSurvivor($key);
            }
        }

        if (count($queue) > $budget && ! $scan->isPullRequest()) {
            $workspace->notes[] = sprintf('%d tested method(s) are still waiting for a mutation check; the budget is %d per scan.', count($queue) - $budget, $budget);
        }

        return [$analysis, $verdicts];
    }

    /**
     * @param  array<string, array{result: MutationResult, fresh: bool, hash: string}>  $mutations
     * @param  list<TestRecord>  $tests
     * @param  list<string>  $notes
     */
    private function record(Scan $scan, Analysis $analysis, array $mutations, array $tests, string $output, array $notes): void
    {
        DB::transaction(function () use ($scan, $analysis, $mutations, $tests, $output, $notes) {
            $app = $scan->fleetApp;
            $existing = $app->surfaces()->get()->keyBy(fn (Surface $s) => $s->kind->value.'|'.$s->identifier);
            $previous = $this->previousScan($scan);
            $before = $previous === null ? [] : DB::table('scan_surfaces')->where('scan_id', $previous->id)->pluck('status', 'surface_id')->all();
            $seen = [];
            $rows = [];
            $index = [];
            $changes = [];

            foreach ($tests as $test) {
                $index[$test->id] = $test;
            }

            foreach ($analysis->results as $key => $result) {
                $found = $result['surface'];
                $seen[$key] = true;
                $mutation = $mutations[$key] ?? null;

                $surface = $existing->get($key) ?? new Surface(['fleet_app_id' => $app->id, 'kind' => $found->kind, 'identifier' => $found->identifier]);
                $surface->fill([
                    'label' => Str::limit($found->label, 500, ''),
                    'file' => $found->file,
                    'start_line' => $found->startLine,
                    'end_line' => $found->endLine,
                    'body_hash' => $found->hash,
                    'meta' => $found->meta ?: null,
                    'status' => $result['status'],
                    'reason' => $result['reason'],
                    'last_scan_id' => $scan->id,
                    'removed_at' => null,
                ]);

                if ($mutation !== null) {
                    $surface->mutation = $mutation['result']->value;
                    $surface->mutation_hash = $mutation['hash'];

                    if ($mutation['fresh']) {
                        $surface->mutation_checked_at = now();
                    }
                }

                $surface->save();

                $change = $before === [] ? null : $this->change($before[$surface->id] ?? null, $result['status']);

                if ($change !== null) {
                    $changes[$change->value] = ($changes[$change->value] ?? 0) + 1;
                }

                $rows[] = [
                    'scan_id' => $scan->id,
                    'surface_id' => $surface->id,
                    'status' => $result['status']->value,
                    'reason' => $result['reason']?->value,
                    'change' => $change?->value,
                    'mutation' => $mutation['result']->value ?? null,
                    'tests_count' => $result['count'],
                    'evidence' => json_encode($this->evidence($result['tests'], $index)),
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('scan_surfaces')->insert($chunk);
            }

            $existing->reject(fn (Surface $s, string $key) => isset($seen[$key]) || $s->removed_at !== null)
                ->each(fn (Surface $s) => $s->update(['removed_at' => now(), 'status' => null, 'reason' => null]));

            $this->insertTests($scan, $analysis, $tests);

            $mutated = fn (MutationResult $r) => count(array_filter($mutations, fn (array $m) => $m['result'] === $r));

            $scan->update([
                ...$this->summary($analysis, $tests),
                'previous_scan_id' => $previous?->id,
                'surfaces_new_untested' => $changes[SurfaceChange::NewUntested->value] ?? 0,
                'surfaces_lost' => $changes[SurfaceChange::Lost->value] ?? 0,
                'surfaces_gained' => $changes[SurfaceChange::Gained->value] ?? 0,
                'mutants_killed' => $mutated(MutationResult::Killed),
                'mutants_survived' => $mutated(MutationResult::Survived),
                'output' => Str::limit($output, 60000, ''),
                'notes' => $notes ?: null,
            ]);
        });
    }

    /**
     * A pull request scan reports and changes nothing: no surface rows, no
     * ratchet, no mutation verdicts — only its tests, its numbers, and its
     * verdict against the main scan of the commit it merges onto.
     *
     * @param  list<TestRecord>  $tests
     * @param  list<string>  $notes
     */
    private function recordPullRequest(Scan $scan, Scan $base, Analysis $analysis, array $tests, string $output, array $notes): void
    {
        $statuses = DB::table('scan_surfaces')
            ->join('surfaces', 'surfaces.id', '=', 'scan_surfaces.surface_id')
            ->where('scan_surfaces.scan_id', $base->id)
            ->get(['surfaces.kind', 'surfaces.identifier', 'scan_surfaces.status'])
            ->mapWithKeys(fn ($row) => [$row->kind.'|'.$row->identifier => SurfaceStatus::from($row->status)])
            ->all();

        $baseFailing = $base->tests()
            ->whereIn('outcome', [TestOutcome::Failed, TestOutcome::Errored])
            ->pluck('test_id')
            ->mapWithKeys(fn (string $id) => [$id => true])
            ->all();

        $verdict = app(PullRequestVerdict::class)->judge($analysis, $statuses, $baseFailing, $tests);

        DB::transaction(function () use ($scan, $analysis, $tests, $output, $notes, $verdict) {
            $this->insertTests($scan, $analysis, $tests);

            $scan->update([
                ...$this->summary($analysis, $tests),
                'verdict' => $verdict['pass'] ? 'pass' : 'fail',
                'verdict_detail' => $verdict,
                'output' => Str::limit($output, 60000, ''),
                'notes' => $notes ?: null,
            ]);
        });
    }

    /**
     * @param  list<TestRecord>  $tests
     * @return array<string, mixed>
     */
    private function summary(Analysis $analysis, array $tests): array
    {
        $count = fn (TestOutcome ...$outcomes) => count(array_filter($tests, fn (TestRecord $t) => in_array($t->outcome, $outcomes, true)));

        return [
            'status' => ScanStatus::Completed,
            'tests_total' => count($tests),
            'tests_passed' => $count(TestOutcome::Passed),
            'tests_failed' => $count(TestOutcome::Failed, TestOutcome::Errored),
            'tests_skipped' => $count(TestOutcome::Skipped, TestOutcome::Incomplete),
            'tests_risky' => $count(TestOutcome::Risky),
            'assertions' => array_sum(array_map(fn (TestRecord $t) => $t->assertions, $tests)),
            'surfaces_total' => count($analysis->results),
            'surfaces_tested' => $analysis->count(SurfaceStatus::Tested),
            'surfaces_weak' => $analysis->count(SurfaceStatus::Weak),
            'surfaces_missing' => $analysis->count(SurfaceStatus::Missing),
            'score' => $analysis->score(),
            'finished_at' => now(),
        ];
    }

    /**
     * @param  list<TestRecord>  $tests
     */
    private function insertTests(Scan $scan, Analysis $analysis, array $tests): void
    {
        $rows = array_map(fn (TestRecord $t) => [
            'scan_id' => $scan->id,
            'test_id' => $t->id,
            'file' => $t->file,
            'name' => Str::limit($t->name, 1000, ''),
            'outcome' => $t->outcome->value,
            'assertions' => $t->assertions,
            'duration_ms' => $t->durationMs,
            'surfaces_count' => $analysis->testSurfaceCounts[$t->id] ?? 0,
            'message' => $t->message,
        ], $tests);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('scan_tests')->insert($chunk);
        }
    }

    /**
     * The main scan a pull request is judged against: the one of the exact
     * commit it merges onto. If there is none yet, one is requested and the PR
     * waits; after max_deferrals rounds it settles for the latest main scan,
     * and says so — a verdict against nearby main beats no verdict.
     */
    private function baseFor(Scan $scan, string $baseSha, Workspace $workspace): ?Scan
    {
        $exact = Scan::query()->main()
            ->where('fleet_app_id', $scan->fleet_app_id)
            ->where('status', ScanStatus::Completed)
            ->where('commit_sha', $baseSha)
            ->whereHas('surfaceResults')
            ->latest('id')
            ->first();

        if ($exact !== null) {
            return $exact;
        }

        if ($scan->deferrals < (int) config('scanner.pull_requests.max_deferrals')) {
            app(ScanRequester::class)->request($scan->fleetApp);

            return null;
        }

        $latest = $scan->fleetApp->latestCompletedScan()->first();

        if ($latest !== null) {
            $workspace->notes[] = 'Main at '.substr($baseSha, 0, 7).' was never scanned, so this was compared with the latest main scan ('.substr((string) $latest->commit_sha, 0, 7).') instead.';
        }

        return $latest;
    }

    /**
     * Put a pull request scan back in the queue until its base is scanned.
     */
    private function defer(Scan $scan, string $baseSha): Scan
    {
        $scan->update([
            'status' => ScanStatus::Queued,
            'started_at' => null,
            'deferrals' => $scan->deferrals + 1,
            'notes' => ['Waiting for main at '.substr($baseSha, 0, 7).' to be scanned, to compare with.'],
        ]);

        return $scan->refresh();
    }

    /**
     * The app's last completed scan that still has its per-surface detail —
     * the baseline the ratchet compares against.
     */
    private function previousScan(Scan $scan): ?Scan
    {
        return Scan::query()
            ->main()
            ->where('fleet_app_id', $scan->fleet_app_id)
            ->where('id', '<', $scan->id)
            ->where('status', ScanStatus::Completed)
            ->whereHas('surfaceResults')
            ->latest('id')
            ->first();
    }

    /**
     * The ratchet: a surface that was tested and is not any more, or one that
     * arrived since the last scan without a test, is a regression.
     */
    private function change(?string $before, SurfaceStatus $now): ?SurfaceChange
    {
        $tested = $now === SurfaceStatus::Tested;

        return match (true) {
            $before === null => $tested ? null : SurfaceChange::NewUntested,
            $before === SurfaceStatus::Tested->value && ! $tested => SurfaceChange::Lost,
            $before !== SurfaceStatus::Tested->value && $tested => SurfaceChange::Gained,
            default => null,
        };
    }

    /**
     * Test names rather than ids, so the evidence reads as a sentence.
     *
     * @param  list<string>  $ids
     * @param  array<string, TestRecord>  $index
     * @return list<array{id: string, name: string, file: ?string, outcome: string}>
     */
    private function evidence(array $ids, array $index): array
    {
        $evidence = [];

        foreach ($ids as $id) {
            if (($test = $index[$id] ?? null) !== null) {
                $evidence[] = ['id' => $test->id, 'name' => $test->name, 'file' => $test->file, 'outcome' => $test->outcome->value];
            }
        }

        return $evidence;
    }

    /**
     * Keep the raw output of the last few runs per app for debugging a
     * verdict; older runs go.
     */
    private function pruneRuns(Workspace $workspace): void
    {
        $runs = dirname($workspace->runDir);
        $keep = max(1, (int) config('scanner.keep_runs'));

        $dirs = collect(File::directories($runs))
            ->sortByDesc(fn (string $dir) => (int) basename($dir))
            ->values();

        $dirs->slice($keep)->each(fn (string $dir) => File::deleteDirectory($dir));
    }
}
