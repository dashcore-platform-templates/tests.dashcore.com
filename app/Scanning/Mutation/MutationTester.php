<?php

namespace App\Scanning\Mutation;

use App\Enums\MutationResult;
use App\Scanning\Evidence\ProbeReader;
use App\Scanning\Evidence\TestRecord;
use App\Scanning\Inventory\DiscoveredSurface;
use App\Scanning\SuiteRunner;
use App\Scanning\Workspace;
use Throwable;

/**
 * Checks that the tests proving a method would notice if it broke: each
 * mutant is written into the workspace, the test files of the proving tests
 * are run, and the original source is put back whatever happens.
 *
 * A mutant is killed when a proving test that passed now fails, errors, or
 * never finishes (a timeout or a crash is the suite noticing). It survives
 * when the suite stays green — the method can stop doing its job and nothing
 * says so.
 */
class MutationTester
{
    private int $runs = 0;

    public function __construct(
        private readonly Mutator $mutator,
        private readonly SuiteRunner $runner,
        private readonly ProbeReader $reader,
    ) {}

    /**
     * @param  list<TestRecord>  $proving  the tests that proved this surface in the full run
     * @return array{result: MutationResult, mutants: int, killed: int, survivor: ?string}
     */
    public function test(Workspace $workspace, DiscoveredSurface $surface, array $proving): array
    {
        $none = ['result' => MutationResult::Error, 'mutants' => 0, 'killed' => 0, 'survivor' => null];

        $path = $workspace->path.'/'.$surface->file;

        if (! $surface->hasLines() || ! is_file($path) || $proving === []) {
            return $none;
        }

        $original = (string) file_get_contents($path);
        $mutants = $this->mutator->mutants($original, (int) $surface->startLine);

        if ($mutants === []) {
            return $none;
        }

        $files = array_values(array_unique(array_filter(array_map(fn (TestRecord $t) => $t->file, $proving))));
        $ids = array_map(fn (TestRecord $t) => $t->id, $proving);

        if ($files === []) {
            return $none;
        }

        $killed = 0;
        $survivor = null;

        try {
            foreach ($mutants as $mutant) {
                file_put_contents($path, $mutant->source);

                $verdict = $this->killed($workspace, $files, $ids, 'mutant-'.(++$this->runs));

                if ($verdict === null) {
                    return $none;
                }

                if ($verdict) {
                    $killed++;
                } else {
                    $survivor ??= $mutant->description;
                }
            }
        } catch (Throwable $e) {
            report($e);

            return $none;
        } finally {
            file_put_contents($path, $original);
        }

        return [
            'result' => $survivor === null ? MutationResult::Killed : MutationResult::Survived,
            'mutants' => count($mutants),
            'killed' => $killed,
            'survivor' => $survivor,
        ];
    }

    /**
     * True when the suite noticed the mutant, false when it stayed green, and
     * null when the run says nothing either way — it died before a single
     * test reported, which is the environment talking, not the tests.
     *
     * @param  list<string>  $files
     * @param  list<string>  $ids
     */
    private function killed(Workspace $workspace, array $files, array $ids, string $name): ?bool
    {
        $run = $this->runner->runFiles($workspace, $files, $name, (int) config('scanner.mutation.timeout'));

        if ($run['timed_out']) {
            return true;
        }

        $outcomes = [];

        foreach ($this->reader->read($run['probe']) as $record) {
            $outcomes[$record->id] = $record->outcome;
        }

        @unlink($run['probe']);

        if ($outcomes === []) {
            return null;
        }

        foreach ($ids as $id) {
            $outcome = $outcomes[$id] ?? null;

            // No record means the run died before this test finished. A test
            // that turned risky or skipped still leaves the suite green.
            if ($outcome === null || $outcome->isFailure()) {
                return true;
            }
        }

        return false;
    }
}
