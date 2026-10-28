<?php

namespace App\Simulation;

use App\Enums\FindingKind;
use App\Enums\FindingStatus;
use App\Enums\SimulationStatus;
use App\Models\SimulationFinding;
use App\Models\SimulationRun;
use App\Models\SimulationStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns what a run ran into into findings. The same problem seen again adds
 * to the same finding (by fingerprint); a finding the journeys stop hitting
 * is resolved after a few clean passes, and reopened if it ever returns.
 */
class Findings
{
    /**
     * Record a finished run's findings and credit the clean ones. Returns how
     * many distinct findings the run hit.
     */
    public function record(SimulationRun $run): int
    {
        $run->loadMissing('steps');
        $hits = $this->detect($run);

        DB::transaction(function () use ($run, $hits) {
            foreach ($hits as $hit) {
                $this->hit($run->target, $hit, $run);
            }

            $this->creditCleanRuns($run, array_keys($hits));

            $run->update(['findings_count' => count($hits)]);
        });

        return count($hits);
    }

    /**
     * @return array<string, array{kind: FindingKind, fingerprint: string, journey: ?string, position: ?int, step_position: ?int, title: string, detail: ?string}>
     */
    private function detect(SimulationRun $run): array
    {
        $hits = [];
        $add = function (FindingKind $kind, string $fingerprint, string $title, ?string $detail, ?SimulationStep $step) use (&$hits, $run) {
            $hits[$kind->value.'|'.$fingerprint] ??= [
                'kind' => $kind,
                'fingerprint' => $fingerprint,
                'journey' => $kind->isStepScoped() || $kind->isElementScoped() ? $run->journey : null,
                'position' => $kind->isStepScoped() ? $step?->position : null,
                'step_position' => $step?->position,
                'title' => Str::limit($title, 250),
                'detail' => $detail === null ? null : Str::limit($detail, 4000),
            ];
        };

        $slowMs = (int) config('simulation.findings.slow_ms');

        foreach ($run->steps as $step) {
            $doing = $this->doing($run, $step);
            $here = "{$run->journey}#{$step->position}";

            if ($step->status === SimulationStatus::Failed) {
                $add(FindingKind::Breaks, $here, "“{$run->journey_name}” breaks when {$doing}", $step->error, $step);
            }

            if ($step->feedback === false) {
                $add(FindingKind::DeadSpace, $this->element($run, $step), "Nothing shows it is working when {$doing}", sprintf(
                    'The click went to the server (%d request%s, %s ms) and no wire:loading state changed while it was in flight.',
                    $step->livewire_requests,
                    $step->livewire_requests === 1 ? '' : 's',
                    $step->livewire_ms ?? '?',
                ), $step);
            }

            if ($step->status === SimulationStatus::Passed && $slowMs > 0 && $step->duration_ms > $slowMs) {
                $add(FindingKind::Slow, $this->element($run, $step), "Slow when {$doing}", sprintf('Took %.1fs; the limit is %.1fs.', $step->duration_ms / 1000, $slowMs / 1000), $step);
            }

            foreach ($step->console ?? [] as $entry) {
                if ($entry['level'] === 'http' && preg_match('/^(\d{3}) (\S+) (\S+)/', $entry['text'], $m)) {
                    $request = "{$m[1]} {$m[2]} ".$this->normalisePath($m[3]);
                    $add(FindingKind::ServerError, "{$run->target}|{$request}", "{$m[1]} from {$m[2]} ".$this->normalisePath($m[3]), "Seen when {$doing}.\n{$entry['text']}", $step);
                }

                if (in_array($entry['level'], ['error', 'exception'], true)) {
                    $message = $this->normaliseMessage($entry['text']);
                    $add(FindingKind::ConsoleError, "{$run->target}|{$message}", $message, "Seen when {$doing}.\n{$entry['text']}", $step);
                }
            }
        }

        return $hits;
    }

    /**
     * Something the server side of an app did that no run can be blamed for:
     * an error in its log, a job that failed.
     */
    public function recordMonitored(string $target, FindingKind $kind, string $fingerprint, string $title, ?string $detail): void
    {
        DB::transaction(fn () => $this->hit($target, [
            'kind' => $kind,
            'fingerprint' => Str::limit("{$target}|{$fingerprint}", 490, ''),
            'journey' => null,
            'position' => null,
            'step_position' => null,
            'title' => Str::limit($title, 250),
            'detail' => $detail === null ? null : Str::limit($detail, 4000),
        ]));
    }

    /**
     * @param  array{kind: FindingKind, fingerprint: string, journey: ?string, position: ?int, step_position: ?int, title: string, detail: ?string}  $hit
     */
    private function hit(string $target, array $hit, ?SimulationRun $run = null): void
    {
        $finding = SimulationFinding::query()
            ->where('kind', $hit['kind'])
            ->where('fingerprint', $hit['fingerprint'])
            ->lockForUpdate()
            ->first();

        $seen = [
            'title' => $hit['title'],
            'detail' => $hit['detail'],
            'last_run_id' => $run?->id,
            'last_position' => $hit['step_position'],
            'last_seen_at' => now(),
            'clean_runs' => 0,
        ];

        if ($finding === null) {
            SimulationFinding::create([
                ...$seen,
                'kind' => $hit['kind'],
                'fingerprint' => $hit['fingerprint'],
                'target' => $target,
                'journey' => $hit['journey'],
                'position' => $hit['position'],
                'status' => FindingStatus::Open,
                'occurrences' => 1,
                'first_seen_at' => now(),
            ]);

            return;
        }

        if ($finding->status === FindingStatus::Resolved) {
            $seen += ['status' => FindingStatus::Open, 'resolved_at' => null, 'reopened_at' => now()];
        }

        $finding->fill($seen);
        $finding->occurrences++;
        $finding->save();
    }

    /**
     * A finding this run could have hit but did not counts a clean run;
     * enough in a row resolve it. A broken step only counts when the same
     * journey got past it, an element finding when any journey used that
     * element cleanly, and an app-wide one when the run passed.
     *
     * @param  list<string>  $hitKeys
     */
    private function creditCleanRuns(SimulationRun $run, array $hitKeys): void
    {
        $passed = $run->steps->where('status', SimulationStatus::Passed);
        $passedPositions = $passed->pluck('position')->all();
        $passedElements = $passed->map(fn (SimulationStep $step) => $this->element($run, $step))->all();
        $resolveAfter = max(1, (int) config('simulation.findings.resolve_after'));
        $quietAfter = max(1, (int) config('simulation.findings.resolve_monitored_after'));

        SimulationFinding::query()
            ->where('status', FindingStatus::Open)
            ->where('target', $run->target)
            ->lockForUpdate()
            ->get()
            ->reject(fn (SimulationFinding $finding) => in_array($finding->kind->value.'|'.$finding->fingerprint, $hitKeys, true))
            ->filter(fn (SimulationFinding $finding) => match (true) {
                // Only a journey walk that finds the stand-in back on its release settles drift (JourneyRuns).
                $finding->kind === FindingKind::Drift => false,
                $finding->kind->isStepScoped() => $finding->journey === $run->journey && in_array($finding->position, $passedPositions, true),
                $finding->kind->isElementScoped() => in_array($finding->fingerprint, $passedElements, true),
                default => $run->status === SimulationStatus::Passed,
            })
            ->each(function (SimulationFinding $finding) use ($resolveAfter, $quietAfter) {
                $finding->clean_runs++;

                if ($finding->clean_runs >= ($finding->kind->isMonitored() ? $quietAfter : $resolveAfter)) {
                    $finding->status = FindingStatus::Resolved;
                    $finding->resolved_at = now();
                }

                $finding->save();
            });
    }

    /**
     * The step in words, whoever walked it: "Maya marks the deal won" becomes
     * "someone marks the deal won", so every persona's run describes the
     * same step the same way.
     */
    private function doing(SimulationRun $run, SimulationStep $step): string
    {
        $label = Str::after($step->label, $run->personaFirstName().' ');

        return "someone {$label}";
    }

    /**
     * Which element a step acted on, on which page: the same for every
     * journey that does the same thing there.
     */
    private function element(SimulationRun $run, SimulationStep $step): string
    {
        return implode('|', [$run->target, $this->normalisePath((string) $step->url), $step->action, (string) $step->target]);
    }

    private function normalisePath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        return preg_replace('#/\d+(?=/|$)#', '/{id}', $path);
    }

    private function normaliseMessage(string $text): string
    {
        $text = preg_replace('#https?://[^/\s]+#', '', $text);
        $text = preg_replace('/\d+/', '#', $text);

        return Str::limit(trim(preg_replace('/\s+/', ' ', $text)), 300);
    }
}
