<?php

namespace App\Scanning;

use App\Enums\ScanStatus;
use App\Enums\SurfaceStatus;
use App\Enums\TestOutcome;
use App\Models\Scan;
use App\Models\Surface;
use App\Scanning\Evidence\ProbeReader;
use App\Scanning\Evidence\TestRecord;
use App\Scanning\Inventory\Inventory;
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

            $workspace = app(Workspace::class, ['app' => $app, 'scan' => $scan]);
            $revision = $workspace->resolveRevision();

            $scan->update([
                'ref' => $revision['ref'],
                'commit_sha' => $revision['sha'],
                'commit_subject' => Str::limit($revision['subject'], 250),
                'test_database' => $workspace->database,
            ]);

            $workspace->prepare($revision['sha']);

            $scan->update(['status' => ScanStatus::Running]);
            $run = $this->runner->run($workspace);

            $scan->update(['status' => ScanStatus::Analysing, 'framework' => $run['framework'], 'exit_code' => $run['exit_code']]);

            $surfaces = $this->inventory->discover($workspace->path, $this->runner->php(), $workspace->environment());
            $tests = $this->reader->read($run['probe']);

            if ($this->reader->skipped > 0) {
                $workspace->notes[] = "{$this->reader->skipped} probe line(s) were incomplete and skipped — the suite may have died mid-test.";
            }

            $analysis = $this->analyzer->analyse($surfaces, $tests);

            $this->record($scan, $analysis, $tests, $run['output'], [...$workspace->notes, ...$this->inventory->notes]);
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
     * @param  list<TestRecord>  $tests
     * @param  list<string>  $notes
     */
    private function record(Scan $scan, Analysis $analysis, array $tests, string $output, array $notes): void
    {
        DB::transaction(function () use ($scan, $analysis, $tests, $output, $notes) {
            $app = $scan->fleetApp;
            $existing = $app->surfaces()->get()->keyBy(fn (Surface $s) => $s->kind->value.'|'.$s->identifier);
            $seen = [];
            $rows = [];
            $index = [];

            foreach ($tests as $test) {
                $index[$test->id] = $test;
            }

            foreach ($analysis->results as $key => $result) {
                $found = $result['surface'];
                $seen[$key] = true;

                $surface = $existing->get($key) ?? new Surface(['fleet_app_id' => $app->id, 'kind' => $found->kind, 'identifier' => $found->identifier]);
                $surface->fill([
                    'label' => Str::limit($found->label, 500, ''),
                    'file' => $found->file,
                    'start_line' => $found->startLine,
                    'end_line' => $found->endLine,
                    'meta' => $found->meta ?: null,
                    'status' => $result['status'],
                    'last_scan_id' => $scan->id,
                    'removed_at' => null,
                ])->save();

                $rows[] = [
                    'scan_id' => $scan->id,
                    'surface_id' => $surface->id,
                    'status' => $result['status']->value,
                    'tests_count' => $result['count'],
                    'evidence' => json_encode($this->evidence($result['tests'], $index)),
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('scan_surfaces')->insert($chunk);
            }

            $existing->reject(fn (Surface $s, string $key) => isset($seen[$key]) || $s->removed_at !== null)
                ->each(fn (Surface $s) => $s->update(['removed_at' => now(), 'status' => null]));

            $testRows = array_map(fn (TestRecord $t) => [
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

            foreach (array_chunk($testRows, 500) as $chunk) {
                DB::table('scan_tests')->insert($chunk);
            }

            $count = fn (TestOutcome ...$outcomes) => count(array_filter($tests, fn (TestRecord $t) => in_array($t->outcome, $outcomes, true)));

            $scan->update([
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
                'output' => Str::limit($output, 60000, ''),
                'notes' => $notes ?: null,
                'finished_at' => now(),
            ]);
        });
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
