<?php

namespace App\Scanning;

use App\Enums\SurfaceStatus;
use App\Enums\VerdictReason;
use App\Scanning\Evidence\TestRecord;
use App\Scanning\Inventory\DiscoveredSurface;

/**
 * Decides, for every surface, whether the suite tests it.
 *
 * A surface is reached by a test when the test hit one of its runtime keys
 * (route, component, action) or executed a line inside it. It is Tested when
 * at least one reaching test proves something — passed, with assertions —
 * Weak when only tests that prove nothing reached it, and Missing otherwise.
 */
class Analyzer
{
    /** How many test names to keep as evidence per surface. */
    private const EVIDENCE = 5;

    /**
     * @param  list<DiscoveredSurface>  $surfaces
     * @param  list<TestRecord>  $tests
     */
    public function analyse(array $surfaces, array $tests): Analysis
    {
        $byKey = [];
        $byFile = [];

        foreach ($tests as $index => $test) {
            foreach (array_keys($test->keys) as $key) {
                $byKey[$key][] = $index;
            }

            foreach ($test->lines as $file => $lines) {
                $byFile[$file][$index] = $lines;
            }
        }

        $results = [];
        $testCounts = [];

        foreach ($surfaces as $surface) {
            $reached = [];

            foreach ($surface->match as $key) {
                foreach ($byKey[TestRecord::normalise($key)] ?? [] as $index) {
                    $reached[$index] = true;
                }
            }

            if ($surface->hasLines()) {
                foreach ($byFile[$surface->file] ?? [] as $index => $lines) {
                    if (! isset($reached[$index]) && $this->within($lines, $surface->startLine, $surface->endLine)) {
                        $reached[$index] = true;
                    }
                }
            }

            $proving = [];
            $other = [];

            foreach (array_keys($reached) as $index) {
                $test = $tests[$index];
                $testCounts[$test->id] = ($testCounts[$test->id] ?? 0) + 1;

                if ($test->proves()) {
                    $proving[] = $test->id;
                } else {
                    $other[] = $test->id;
                }
            }

            $status = match (true) {
                $proving !== [] => SurfaceStatus::Tested,
                $other !== [] => SurfaceStatus::Weak,
                default => SurfaceStatus::Missing,
            };

            $results[$surface->key()] = [
                'surface' => $surface,
                'status' => $status,
                'reason' => $status === SurfaceStatus::Weak ? VerdictReason::NoProvingTest : null,
                'tests' => array_slice($proving !== [] ? $proving : $other, 0, self::EVIDENCE),
                'proving' => $proving,
                'count' => count($reached),
            ];
        }

        return new Analysis($results, $testCounts);
    }

    /**
     * @param  list<int>  $lines
     */
    private function within(array $lines, int $start, int $end): bool
    {
        foreach ($lines as $line) {
            if ($line >= $start && $line <= $end) {
                return true;
            }
        }

        return false;
    }
}
