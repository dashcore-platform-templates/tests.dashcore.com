<?php

namespace Tests\Unit\Scanning;

use App\Enums\SurfaceKind;
use App\Enums\SurfaceStatus;
use App\Enums\TestOutcome;
use App\Scanning\Analyzer;
use App\Scanning\Evidence\TestRecord;
use App\Scanning\Inventory\DiscoveredSurface;
use App\Scanning\PullRequestVerdict;
use PHPUnit\Framework\TestCase;

class PullRequestVerdictTest extends TestCase
{
    private function route(string $uri): DiscoveredSurface
    {
        return new DiscoveredSurface(SurfaceKind::Route, 'GET '.$uri, 'GET /'.$uri, match: ['GET '.$uri]);
    }

    private function test(string $id, array $keys = [], TestOutcome $outcome = TestOutcome::Passed): TestRecord
    {
        return new TestRecord($id, 'tests/X.php', $id, $outcome, 1, 1, null, array_fill_keys($keys, true), []);
    }

    public function test_a_pr_that_adds_only_tested_code_and_breaks_nothing_passes(): void
    {
        $tests = [$this->test('a', ['GET reports', 'GET exports'])];
        $analysis = (new Analyzer)->analyse([$this->route('reports'), $this->route('exports')], $tests);

        $verdict = (new PullRequestVerdict)->judge($analysis, ['route|GET reports' => SurfaceStatus::Tested], [], $tests);

        $this->assertTrue($verdict['pass']);
        $this->assertSame([], $verdict['new_untested']);
    }

    public function test_new_untested_code_lost_coverage_and_new_failures_each_block(): void
    {
        $tests = [
            $this->test('a', ['GET reports']),
            $this->test('broken', outcome: TestOutcome::Failed),
            $this->test('was_broken', outcome: TestOutcome::Failed),
        ];
        $analysis = (new Analyzer)->analyse([$this->route('reports'), $this->route('exports'), $this->route('billing'), $this->route('old')], $tests);

        $verdict = (new PullRequestVerdict)->judge($analysis, [
            'route|GET reports' => SurfaceStatus::Missing,
            'route|GET billing' => SurfaceStatus::Tested,
            'route|GET old' => SurfaceStatus::Missing,
        ], ['was_broken' => true], $tests);

        $this->assertFalse($verdict['pass']);
        $this->assertSame(['Routes: GET /exports'], $verdict['new_untested']);
        $this->assertSame(['Routes: GET /billing'], $verdict['lost']);
        $this->assertSame(['Routes: GET /reports'], $verdict['newly_tested'], 'better is reported, never required');
        $this->assertSame(['broken (tests/X.php)'], $verdict['new_failures'], 'a failure main already had is not the PR\'s');
    }

    public function test_untested_code_the_pr_inherited_does_not_block_it(): void
    {
        $analysis = (new Analyzer)->analyse([$this->route('old')], []);

        $this->assertTrue((new PullRequestVerdict)->judge($analysis, ['route|GET old' => SurfaceStatus::Missing], [], [])['pass']);
    }

    public function test_the_status_line_says_what_matters_in_140_characters(): void
    {
        $verdicts = new PullRequestVerdict;

        $this->assertSame('92.4% · no new untested code · 2 newly tested', $verdicts->describe(['pass' => true, 'new_untested' => [], 'lost' => [], 'newly_tested' => ['a', 'b'], 'new_failures' => []], 92.4));
        $this->assertSame('2 new untested · 1 lost coverage · 1 new failing test', $verdicts->describe(['pass' => false, 'new_untested' => ['a', 'b'], 'lost' => ['c'], 'newly_tested' => [], 'new_failures' => ['d']], 80.0));
    }
}
