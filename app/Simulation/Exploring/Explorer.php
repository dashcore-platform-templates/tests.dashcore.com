<?php

namespace App\Simulation\Exploring;

use App\Enums\ExplorationStatus;
use App\Enums\JourneyStatus;
use App\Enums\SurfaceStatus;
use App\Models\SimulationExploration;
use App\Models\SimulationJourney;
use App\Simulation\LiveCoverage;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs Claude Code headless over a fake app's source to draft journeys no one
 * has written yet. The agent may only read — no edits, no shell, no network,
 * no MCP servers — and a budget caps what one exploration may spend. What it
 * drafts is checked here, then goes on trial in the real browser; nothing it
 * says is taken on trust.
 */
class Explorer
{
    public function __construct(
        private ExplorePrompt $prompt,
        private DraftValidator $validator,
        private LiveCoverage $coverage,
    ) {}

    public function explore(SimulationExploration $exploration): SimulationExploration
    {
        $exploration->update(['status' => ExplorationStatus::Running, 'started_at' => now()]);

        try {
            $checkout = $this->checkout($exploration->target);
            $result = $this->ask($checkout, $this->prompt->build($exploration->target, $exploration->focus, $this->gaps($exploration->target)));
            $this->keep($exploration, $result);
        } catch (Throwable $e) {
            $exploration->update([
                'status' => ExplorationStatus::Failed,
                'error' => Str::limit($e->getMessage(), 2000),
                'finished_at' => now(),
            ]);
        }

        return $exploration->refresh();
    }

    /**
     * @return array{answer: array<string, mixed>, cost: ?float, output: string}
     */
    private function ask(string $checkout, string $prompt): array
    {
        $command = [
            (string) config('simulation.explore.claude'),
            '-p', $prompt,
            '--output-format', 'json',
            '--json-schema', json_encode(ExplorePrompt::schema(), JSON_THROW_ON_ERROR),
            '--allowedTools', 'Read,Glob,Grep',
            '--disallowedTools', 'Edit,Write,NotebookEdit,Bash,WebFetch,WebSearch,Agent',
            '--strict-mcp-config',
            '--no-session-persistence',
            '--max-budget-usd', (string) config('simulation.explore.budget_usd'),
        ];

        if (($model = config('simulation.explore.model')) !== null && $model !== '') {
            array_push($command, '--model', (string) $model);
        }

        try {
            $result = Process::path($checkout)
                ->timeout((int) config('simulation.explore.timeout'))
                ->env(array_filter(['PATH' => config('scanner.path') ? config('scanner.path').':'.getenv('PATH') : null]))
                ->run($command);
        } catch (ProcessTimedOutException) {
            throw new \RuntimeException('The explorer ran past '.config('simulation.explore.timeout').' seconds and was stopped.');
        }

        $json = json_decode($result->output(), true);

        if (! is_array($json) || ($json['is_error'] ?? false) || ! $result->successful()) {
            throw new \RuntimeException('The explorer did not finish: '.Str::limit(trim(($json['result'] ?? $result->output())."\n".$result->errorOutput()), 1500));
        }

        $answer = $json['structured_output'] ?? json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', (string) ($json['result'] ?? ''))), true);

        if (! is_array($answer) || ! is_array($answer['journeys'] ?? null)) {
            throw new \RuntimeException('The explorer answered without any journeys: '.Str::limit((string) ($json['result'] ?? ''), 1500));
        }

        return [
            'answer' => $answer,
            'cost' => isset($json['total_cost_usd']) ? (float) $json['total_cost_usd'] : null,
            'output' => $result->output(),
        ];
    }

    /**
     * Put every sound draft on trial; record the others with why not.
     *
     * @param  array{answer: array<string, mixed>, cost: ?float, output: string}  $result
     */
    private function keep(SimulationExploration $exploration, array $result): void
    {
        DB::transaction(function () use ($exploration, $result) {
            $drafted = 0;
            $turnedAway = [];

            foreach (array_slice($result['answer']['journeys'], 0, (int) config('simulation.explore.drafts')) as $draft) {
                $problems = is_array($draft) ? $this->validator->problems($draft, $exploration->target) : ['It is not a journey.'];

                if ($problems !== []) {
                    $turnedAway[] = '“'.Str::limit((string) ($draft['name'] ?? $draft['key'] ?? '?'), 80).'”: '.implode(' ', $problems);

                    continue;
                }

                SimulationJourney::create([
                    'key' => "{$exploration->target}.{$draft['key']}",
                    'target' => $exploration->target,
                    'name' => Str::limit($draft['name'], 250),
                    'why' => $draft['why'] ?? null,
                    'roles' => array_values($draft['roles']),
                    'weight' => max(1, min(3, (int) ($draft['weight'] ?? 1))),
                    'steps' => array_values($draft['steps']),
                    'summary' => isset($draft['summary']) ? Str::limit($draft['summary'], 250) : null,
                    'status' => JourneyStatus::Trial,
                    'exploration_id' => $exploration->id,
                ]);
                $drafted++;
            }

            $report = trim(implode("\n\n", array_filter([
                $result['answer']['notes'] ?? null,
                $turnedAway === [] ? null : "Turned away:\n".implode("\n", $turnedAway),
            ])));

            $exploration->update([
                'status' => ExplorationStatus::Completed,
                'drafted' => $drafted,
                'rejected' => count($turnedAway),
                'cost_usd' => $result['cost'],
                'report' => $report === '' ? null : $report,
                'output' => Str::limit($result['output'], 200000),
                'finished_at' => now(),
            ]);
        });
    }

    /**
     * The app's surfaces no journey reaches yet, untested ones first.
     *
     * @return list<string>
     */
    private function gaps(string $target): array
    {
        $coverage = $this->coverage->forTarget($target);

        if ($coverage['app'] === null) {
            return [];
        }

        return $coverage['app']->surfaces()->current()
            ->whereIn('kind', ['route', 'component', 'action'])
            ->whereNotIn('id', $coverage['reachedIds'])
            ->orderByRaw("case status when 'missing' then 0 when 'weak' then 1 else 2 end")
            ->orderBy('label')
            ->get(['label', 'status'])
            ->map(fn ($s) => $s->label.match ($s->status) {
                SurfaceStatus::Missing => ' (no test proves it)',
                SurfaceStatus::Weak => ' (only weakly tested)',
                default => '',
            })
            ->all();
    }

    private function checkout(string $target): string
    {
        $path = config("simulation.targets.{$target}.checkout");

        if (! is_string($path) || ! is_dir($path)) {
            throw new \RuntimeException("No checkout of the {$target} app to read: set simulation.targets.{$target}.checkout (".var_export($path, true).').');
        }

        return $path;
    }
}
