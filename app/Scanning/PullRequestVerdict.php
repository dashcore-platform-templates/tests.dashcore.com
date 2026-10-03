<?php

namespace App\Scanning;

use App\Enums\SurfaceStatus;
use App\Scanning\Evidence\TestRecord;

/**
 * Whether a pull request keeps the ratchet: compared with the main commit it
 * merges onto, it may not add code no test proves, may not take coverage
 * away from code that had it, and may not break a test that passed.
 *
 * Code that merely got better is reported, never required: a PR is judged
 * on what it changes, not on the debt it inherits.
 */
final class PullRequestVerdict
{
    /** How many entries each list keeps, for the status page and the PR. */
    private const LIMIT = 50;

    /**
     * @param  array<string, SurfaceStatus>  $base  the base scan's verdict per surface key
     * @param  array<string, true>  $baseFailing  tests failing on the base
     * @param  list<TestRecord>  $tests  the PR's tests
     * @return array{pass: bool, new_untested: list<string>, lost: list<string>, newly_tested: list<string>, new_failures: list<string>}
     */
    public function judge(Analysis $analysis, array $base, array $baseFailing, array $tests): array
    {
        $newUntested = [];
        $lost = [];
        $gained = [];

        foreach ($analysis->results as $key => $result) {
            $before = $base[$key] ?? null;
            $tested = $result['status'] === SurfaceStatus::Tested;
            $label = $result['surface']->kind->label().': '.$result['surface']->label;

            if ($before === null && ! $tested) {
                $newUntested[] = $label;
            } elseif ($before === SurfaceStatus::Tested && ! $tested) {
                $lost[] = $label;
            } elseif ($before !== null && $before !== SurfaceStatus::Tested && $tested) {
                $gained[] = $label;
            }
        }

        $newFailures = [];

        foreach ($tests as $test) {
            if ($test->outcome->isFailure() && ! isset($baseFailing[$test->id])) {
                $newFailures[] = $test->name.($test->file ? " ({$test->file})" : '');
            }
        }

        return [
            'pass' => $newUntested === [] && $lost === [] && $newFailures === [],
            'new_untested' => array_slice($newUntested, 0, self::LIMIT),
            'lost' => array_slice($lost, 0, self::LIMIT),
            'newly_tested' => array_slice($gained, 0, self::LIMIT),
            'new_failures' => array_slice($newFailures, 0, self::LIMIT),
        ];
    }

    /**
     * The commit status line: at most 140 characters, what matters first.
     *
     * @param  array{pass: bool, new_untested: list<string>, lost: list<string>, newly_tested: list<string>, new_failures: list<string>}  $verdict
     */
    public function describe(array $verdict, ?float $score): string
    {
        if ($verdict['pass']) {
            $gained = count($verdict['newly_tested']);

            return mb_substr(($score === null ? '' : number_format($score, 1).'% · ').'no new untested code'.($gained > 0 ? " · {$gained} newly tested" : ''), 0, 140);
        }

        $parts = array_filter([
            count($verdict['new_untested']) ? count($verdict['new_untested']).' new untested' : null,
            count($verdict['lost']) ? count($verdict['lost']).' lost coverage' : null,
            count($verdict['new_failures']) ? count($verdict['new_failures']).' new failing '.(count($verdict['new_failures']) === 1 ? 'test' : 'tests') : null,
        ]);

        return mb_substr(implode(' · ', $parts), 0, 140);
    }
}
