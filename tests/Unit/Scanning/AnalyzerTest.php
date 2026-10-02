<?php

namespace Tests\Unit\Scanning;

use App\Enums\SurfaceKind;
use App\Enums\SurfaceStatus;
use App\Enums\TestOutcome;
use App\Scanning\Analyzer;
use App\Scanning\Evidence\TestRecord;
use App\Scanning\Inventory\DiscoveredSurface;
use PHPUnit\Framework\TestCase;

class AnalyzerTest extends TestCase
{
    private function test(string $id, array $keys = [], array $lines = [], TestOutcome $outcome = TestOutcome::Passed, int $assertions = 1): TestRecord
    {
        return new TestRecord($id, 'tests/X.php', $id, $outcome, $assertions, 1, null, array_fill_keys($keys, true), $lines);
    }

    public function test_a_surface_hit_by_a_passing_asserting_test_is_tested(): void
    {
        $route = new DiscoveredSurface(SurfaceKind::Route, 'GET reports', 'GET /reports', match: ['GET reports']);

        $analysis = (new Analyzer)->analyse([$route], [$this->test('a', ['GET reports'])]);

        $result = $analysis->results[$route->key()];
        $this->assertSame(SurfaceStatus::Tested, $result['status']);
        $this->assertSame(['a'], $result['tests']);
        $this->assertSame(100.0, $analysis->score());
    }

    public function test_code_is_tested_only_by_lines_inside_its_body(): void
    {
        $method = new DiscoveredSurface(SurfaceKind::Job, 'Job::handle', 'Job::handle', 'app/Jobs/Job.php', 10, 14);

        $outside = (new Analyzer)->analyse([$method], [$this->test('a', lines: ['app/Jobs/Job.php' => [5, 9, 15]])]);
        $inside = (new Analyzer)->analyse([$method], [$this->test('a', lines: ['app/Jobs/Job.php' => [5, 12]])]);
        $otherFile = (new Analyzer)->analyse([$method], [$this->test('a', lines: ['app/Jobs/Other.php' => [12]])]);

        $this->assertSame(SurfaceStatus::Missing, $outside->results[$method->key()]['status']);
        $this->assertSame(SurfaceStatus::Tested, $inside->results[$method->key()]['status']);
        $this->assertSame(SurfaceStatus::Missing, $otherFile->results[$method->key()]['status']);
    }

    public function test_reached_only_by_tests_that_prove_nothing_is_weak(): void
    {
        $route = new DiscoveredSurface(SurfaceKind::Route, 'GET a', 'GET /a', match: ['GET a']);

        foreach ([
            'no assertions' => $this->test('t', ['GET a'], assertions: 0),
            'failed' => $this->test('t', ['GET a'], outcome: TestOutcome::Failed),
            'errored' => $this->test('t', ['GET a'], outcome: TestOutcome::Errored),
            'risky' => $this->test('t', ['GET a'], outcome: TestOutcome::Risky),
        ] as $case => $test) {
            $analysis = (new Analyzer)->analyse([$route], [$test]);

            $this->assertSame(SurfaceStatus::Weak, $analysis->results[$route->key()]['status'], $case);
            $this->assertSame(0.0, $analysis->score(), $case);
        }
    }

    public function test_one_proving_test_outweighs_any_number_of_weak_ones_and_is_listed_first(): void
    {
        $route = new DiscoveredSurface(SurfaceKind::Route, 'GET a', 'GET /a', match: ['GET a']);

        $analysis = (new Analyzer)->analyse([$route], [
            $this->test('weak', ['GET a'], assertions: 0),
            $this->test('good', ['GET a']),
        ]);

        $this->assertSame(SurfaceStatus::Tested, $analysis->results[$route->key()]['status']);
        $this->assertSame(['good'], $analysis->results[$route->key()]['tests']);
        $this->assertSame(2, $analysis->results[$route->key()]['count']);
    }

    public function test_component_names_match_with_or_without_the_single_file_marker(): void
    {
        $component = new DiscoveredSurface(SurfaceKind::Component, 'resources/views/components/⚡counter.blade.php', 'counter', match: ['counter']);

        $analysis = (new Analyzer)->analyse([$component], [
            TestRecord::fromProbe(['id' => 'a', 'status' => 'passed', 'assertions' => 1, 'mounts' => ['⚡counter']]),
        ]);

        $this->assertSame(SurfaceStatus::Tested, $analysis->results[$component->key()]['status']);
    }

    public function test_untouched_surfaces_are_missing_and_counts_add_up(): void
    {
        $surfaces = [
            new DiscoveredSurface(SurfaceKind::Route, 'GET a', 'GET /a', match: ['GET a']),
            new DiscoveredSurface(SurfaceKind::Route, 'GET b', 'GET /b', match: ['GET b']),
            new DiscoveredSurface(SurfaceKind::Route, 'GET c', 'GET /c', match: ['GET c']),
            new DiscoveredSurface(SurfaceKind::Route, 'GET d', 'GET /d', match: ['GET d']),
        ];

        $analysis = (new Analyzer)->analyse($surfaces, [
            $this->test('t1', ['GET a']),
            $this->test('t2', ['GET b'], assertions: 0),
        ]);

        $this->assertSame(1, $analysis->count(SurfaceStatus::Tested));
        $this->assertSame(1, $analysis->count(SurfaceStatus::Weak));
        $this->assertSame(2, $analysis->count(SurfaceStatus::Missing));
        $this->assertSame(25.0, $analysis->score());
        $this->assertSame(['t1' => 1, 't2' => 1], $analysis->testSurfaceCounts);
    }

    public function test_an_app_with_no_surfaces_has_no_score(): void
    {
        $this->assertNull((new Analyzer)->analyse([], [])->score());
    }
}
