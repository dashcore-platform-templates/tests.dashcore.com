<?php

namespace App\Library;

use App\Models\FleetApp;
use App\Models\LibraryFile;
use App\Models\LibraryTest;
use App\Models\Scan;
use App\Models\ScanTest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The library: every test of every app, kept here even though each runs in
 * its app's own repo. Test files are read straight from the app's git history
 * at the commit a scan ran (no checkout needed), a new revision is kept when a
 * file changes, and every test is tied to its code, its outcome scan after
 * scan, and how many surfaces it proves.
 */
class Library
{
    /** Files larger than this are noted, not stored. */
    private const MAX_FILE_BYTES = 512_000;

    public function __construct(private TestParser $parser) {}

    /**
     * Bring an app's library up to date with a completed scan: its test
     * files at the scan's commit, and its tests with the scan's outcomes.
     *
     * @return array{files: int, changed: int, tests: int}
     */
    public function captureScan(Scan $scan): array
    {
        $app = $scan->fleetApp;

        if (! $app->local_path || ! $scan->commit_sha) {
            throw new RuntimeException("Scan #{$scan->id} has no checkout or commit to read tests from.");
        }

        return $this->capture($app, $app->local_path, $scan->commit_sha, $scan);
    }

    /**
     * @return array{files: int, changed: int, tests: int}
     */
    public function capture(FleetApp $app, string $repo, string $commit, ?Scan $scan = null): array
    {
        $files = $this->readFiles($repo, $commit);
        $committed = Process::path($repo)->run(['git', 'show', '-s', '--format=%cI', $commit]);
        $at = $committed->successful() && trim($committed->output()) !== '' ? Carbon::parse(trim($committed->output())) : now();

        return DB::transaction(function () use ($app, $commit, $scan, $files, $at) {
            $changed = $this->storeFiles($app, $commit, $files, $at);
            $tests = $this->storeTests($app, $scan);

            return ['files' => count($files), 'changed' => $changed, 'tests' => $tests];
        });
    }

    /**
     * Every PHP test file at the commit — tests/, and the tests of packages
     * kept in the repo — read from git.
     *
     * @return array<string, string> path => content
     */
    private function readFiles(string $repo, string $commit): array
    {
        $list = Process::path($repo)->timeout(120)->run(['git', 'ls-tree', '-r', '-l', '--full-tree', $commit, '--', 'tests', 'packages']);

        if (! $list->successful()) {
            throw new RuntimeException("Could not list tests at {$commit}: ".trim($list->errorOutput()));
        }

        $files = [];

        foreach (preg_split('/\R/', trim($list->output())) as $line) {
            // <mode> blob <sha> <size>\t<path>
            if (! preg_match('/^\d+ blob [0-9a-f]+\s+(\d+)\t(.+)$/', $line, $m) || ! str_ends_with($m[2], '.php')) {
                continue;
            }

            // The app's own tests, and those of packages kept in its repo.
            if (! str_starts_with($m[2], 'tests/') && ! preg_match('#^packages/.+/tests/#', $m[2])) {
                continue;
            }

            if ((int) $m[1] > self::MAX_FILE_BYTES) {
                continue;
            }

            $show = Process::path($repo)->timeout(60)->run(['git', 'show', "{$commit}:{$m[2]}"]);

            if ($show->successful()) {
                $files[$m[2]] = $show->output();
            }
        }

        return $files;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function storeFiles(FleetApp $app, string $commit, array $files, Carbon $at): int
    {
        $existing = LibraryFile::query()->where('fleet_app_id', $app->id)->get()->keyBy('path');
        $changed = 0;

        foreach ($files as $path => $content) {
            $hash = sha1($content);
            $lines = substr_count($content, "\n") + 1;
            $file = $existing->get($path);

            if ($file !== null && $file->hash === $hash && $file->removed_at === null) {
                $file->update(['commit_sha' => $commit]);

                continue;
            }

            // Dated by the commit it was read at, not by when it was read.
            $file ??= new LibraryFile(['fleet_app_id' => $app->id, 'path' => $path, 'first_seen_at' => $at]);
            $file->fill([
                'hash' => $hash,
                'content' => $content,
                'lines' => $lines,
                'commit_sha' => $commit,
                'changed_at' => $at,
                'removed_at' => null,
            ])->save();

            $file->revisions()->create([
                'hash' => $hash,
                'commit_sha' => $commit,
                'content' => $content,
                'lines' => $lines,
                'created_at' => $at,
            ]);

            $changed++;
        }

        LibraryFile::query()
            ->where('fleet_app_id', $app->id)
            ->whereNull('removed_at')
            ->whereNotIn('path', array_keys($files) ?: [''])
            ->update(['removed_at' => now()]);

        return $changed;
    }

    /**
     * Every test the code declares or a scan ran: what a scan reported is
     * matched to the code that declares it (PHPUnit by Class::method, Pest by
     * name), and a declared test no scan ran is kept as never run.
     */
    private function storeTests(FleetApp $app, ?Scan $scan): int
    {
        $scan ??= $app->latestCompletedScan;
        $files = LibraryFile::query()->where('fleet_app_id', $app->id)->whereNull('removed_at')->get()->keyBy('path');

        $declared = [];
        foreach ($files as $path => $file) {
            foreach ($this->parser->parse($file->content) as $declaration) {
                $declared[] = [...$declaration, 'path' => $path];
            }
        }

        $ran = $scan ? ScanTest::query()->where('scan_id', $scan->id)->get() : collect();
        $proves = $scan ? $this->provedPerTest($scan) : [];
        $seen = [];
        $claimed = [];

        foreach ($ran as $result) {
            $match = $this->declarationFor($result, $declared, $claimed);
            $file = $files->get($match['path'] ?? $result->file);

            $this->upsertTest($app, $result->test_id, [
                'library_file_id' => $file?->id,
                'name' => $result->name,
                'style' => $match['style'] ?? (str_contains($result->test_id, '__pest_evaluable_') ? 'pest' : 'phpunit'),
                'start_line' => $match['start'] ?? null,
                'end_line' => $match['end'] ?? null,
                'last_outcome' => $result->outcome->value,
                'last_assertions' => $result->assertions,
                'last_duration_ms' => $result->duration_ms,
                'surfaces_count' => $proves[$result->test_id] ?? 0,
                'last_scan_id' => $scan->id,
            ], ['scan' => $scan->id, 'outcome' => $result->outcome->value, 'at' => ($scan->finished_at ?? now())->toIso8601String()]);

            $seen[] = LibraryTest::hashKey($result->test_id);
        }

        foreach ($declared as $i => $declaration) {
            if (isset($claimed[$i])) {
                continue;
            }

            $key = $declaration['key'] ?? "{$declaration['path']}::{$declaration['name']}";

            if (in_array(LibraryTest::hashKey($key), $seen, true)) {
                continue;
            }

            $this->upsertTest($app, $key, [
                'library_file_id' => $files->get($declaration['path'])?->id,
                'name' => $declaration['name'],
                'style' => $declaration['style'],
                'start_line' => $declaration['start'],
                'end_line' => $declaration['end'],
            ]);

            $seen[] = LibraryTest::hashKey($key);
        }

        LibraryTest::query()
            ->where('fleet_app_id', $app->id)
            ->whereNull('removed_at')
            ->whereNotIn('test_key_hash', $seen ?: [''])
            ->update(['removed_at' => now()]);

        foreach ($files as $file) {
            $file->update(['tests_count' => LibraryTest::query()->where('library_file_id', $file->id)->whereNull('removed_at')->count()]);
        }

        return count($seen);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{scan: int, outcome: string, at: string}|null  $outcome
     */
    private function upsertTest(FleetApp $app, string $key, array $attributes, ?array $outcome = null): void
    {
        $test = LibraryTest::query()->firstOrNew(
            ['fleet_app_id' => $app->id, 'test_key_hash' => LibraryTest::hashKey($key)],
            ['test_key' => Str::limit($key, 1000, ''), 'first_seen_at' => now()],
        );

        $history = $test->history ?? [];

        if ($outcome !== null && (end($history)['scan'] ?? null) !== $outcome['scan']) {
            $history = array_slice([...$history, $outcome], -LibraryTest::HISTORY);
        }

        $test->fill([...$attributes, 'history' => $history ?: null, 'last_seen_at' => now(), 'removed_at' => null])->save();
    }

    /**
     * The declaration a scan's result came from, if the code shows it.
     *
     * @param  list<array<string, mixed>>  $declared
     * @param  array<int, true>  $claimed
     * @return array<string, mixed>|null
     */
    private function declarationFor(ScanTest $result, array $declared, array &$claimed): ?array
    {
        foreach ($declared as $i => $declaration) {
            if ($declaration['path'] !== $result->file) {
                continue;
            }

            $hit = $declaration['key'] !== null
                // A data provider's runs are reported as Class::method#set.
                ? $declaration['key'] === preg_replace('/#.*$/s', '', $result->test_id)
                // Pest names a dataset's runs "<name> with data set …".
                : $result->name === $declaration['name'] || str_starts_with($result->name, $declaration['name'].' with ');

            if ($hit) {
                $claimed[$i] = true;

                return $declaration;
            }
        }

        return null;
    }

    /**
     * How many surfaces each test proved in a scan, from its evidence.
     *
     * @return array<string, int>
     */
    private function provedPerTest(Scan $scan): array
    {
        $counts = [];

        DB::table('scan_surfaces')->where('scan_id', $scan->id)->whereNotNull('evidence')->orderBy('id')
            ->each(function (object $row) use (&$counts) {
                foreach (json_decode((string) $row->evidence, true) ?: [] as $evidence) {
                    if (($evidence['outcome'] ?? null) === 'passed' && isset($evidence['id'])) {
                        $counts[$evidence['id']] = ($counts[$evidence['id']] ?? 0) + 1;
                    }
                }
            });

        return $counts;
    }
}
