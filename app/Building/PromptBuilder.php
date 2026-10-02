<?php

namespace App\Building;

use App\Enums\SurfaceKind;
use App\Enums\VerdictReason;
use App\Models\Build;
use App\Models\ScanSurface;
use App\Models\Surface;
use Illuminate\Support\Collection;

/**
 * The brief an agent gets for a build: what to cover, what "covered" means
 * here, and the rules that verification will hold it to anyway.
 */
class PromptBuilder
{
    /**
     * @param  Collection<int, Surface>  $targets
     */
    public function build(Build $build, Collection $targets, string $framework): string
    {
        $app = $build->fleetApp;
        $style = $framework === 'pest' ? 'Pest (functional `test()`/`it()` style)' : 'PHPUnit classes';
        $lines = [];

        foreach ($targets as $surface) {
            $lines[] = '- '.$this->describe($surface, $build);
        }

        $targetList = implode("\n", $lines);

        return <<<PROMPT
            You are writing tests for {$app->name} ({$app->host}), a Laravel app. Its tests use {$style}.

            tests.dashcore.com measured this app's suite and found surfaces no test proves. Write tests that prove these, and nothing else:

            {$targetList}

            What "proves" means here — your work is checked against exactly this after you finish:
            1. A test proves a surface when it passes, makes at least one assertion, and actually executes it: hits the route, mounts the component, calls the Livewire action, or runs the method's lines.
            2. Each target method is then mutation-checked: its whole body is replaced with the most trivial value its return type allows (nothing for void, 0 and 1 for int, true and false for bool, '' for string, [] for array, null where allowed) and your tests are run again. At least one of them must fail for every such mutant. So assert the actual outcome — exact return values, database rows created or changed, events, mail, notifications, jobs dispatched, redirects, validation errors, what the user sees — never just "it ran" or a status code alone.
            3. For a route, cover who may and may not use it, from its middleware: a guest, a user without access (expect the denial), and a user with access (expect the real behaviour).
            4. The whole suite is run afterwards; your tests must not break or depend on other tests.

            Rules:
            - Only create or edit files under tests/ — and database/factories/ if a model you need has no factory. Never change application code, config, routes, migrations, views, phpunit.xml, composer or package files. Changes anywhere else are thrown away.
            - Follow this suite's existing conventions: read two or three nearby tests first and match their structure, helpers, traits (e.g. RefreshDatabase), factories and naming. Put each test where a maintainer would look for it.
            - Prefer driving the app through its real entry points (HTTP, Livewire::test, artisan, dispatching the job) over calling internals.
            - Fake external services the way the suite already does (Http::fake, Mail::fake, Queue::fake, …). No real network calls.
            - No skipped, incomplete or empty tests. No sleeping.
            - Run the tests you wrote (php artisan test --compact <file>, or vendor/bin/{$framework} <file>) until they pass. If vendor/bin/pint exists, run it on the files you created.
            - If a target behaves in a way that looks like a bug, do not change the app and do not write a test that enshrines the bug. Leave that target out and report it.
            - If a target genuinely cannot be tested from the outside, leave it out and say why.

            When you are done, end your reply with this JSON in a ```json block and nothing after it:
            {"tests": [{"file": "tests/...", "covers": ["<target id>", ...]}], "suspected_bugs": [{"target": "<target id>", "description": "..."}], "skipped": [{"target": "<target id>", "reason": "..."}]}
            PROMPT;
    }

    private function describe(Surface $surface, Build $build): string
    {
        $parts = ["[{$surface->id}] {$surface->kind->label()}: {$surface->label}"];

        if ($surface->file !== null) {
            $parts[] = $surface->file.($surface->start_line ? ':'.$surface->start_line.'-'.$surface->end_line : '');
        }

        if ($surface->kind === SurfaceKind::Route) {
            if ($name = $surface->meta['name'] ?? null) {
                $parts[] = "named {$name}";
            }
            if ($action = $surface->meta['action'] ?? null) {
                $parts[] = "handled by {$action}";
            }
            if ($middleware = $surface->meta['middleware'] ?? null) {
                $parts[] = 'middleware: '.implode(', ', $middleware);
            }
        }

        $parts[] = match (true) {
            $surface->reason === VerdictReason::MutantSurvived => 'existing tests reach it but still pass when its body is gutted — add assertions that would fail',
            $surface->reason === VerdictReason::NoProvingTest => 'only reached by failing or assertion-free tests',
            default => 'no test reaches it',
        };

        $evidence = $build->scan_id === null ? null : ScanSurface::query()
            ->where('scan_id', $build->scan_id)
            ->where('surface_id', $surface->id)
            ->value('evidence');

        $files = collect(is_string($evidence) ? json_decode($evidence, true) : $evidence)->pluck('file')->filter()->unique();

        if ($files->isNotEmpty()) {
            $parts[] = 'tests that already touch it: '.$files->implode(', ');
        }

        return implode(' — ', $parts);
    }
}
