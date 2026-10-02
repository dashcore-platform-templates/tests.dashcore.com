<?php

namespace App\Building;

use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\Surface;
use App\Scanning\CheckoutLocator;
use App\Scanning\Workspace;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * One build, end to end: branch from the default branch, let the agent write
 * tests, verify them independently, and propose them for review if — and
 * only if — they hold up.
 */
class Builder
{
    public function __construct(
        private readonly CheckoutLocator $checkouts,
        private readonly PromptBuilder $prompts,
        private readonly AgentRunner $agent,
        private readonly BuildVerifier $verifier,
        private readonly Publisher $publisher,
    ) {}

    public function run(Build $build): Build
    {
        $app = $build->fleetApp;
        $build->update(['status' => BuildStatus::Preparing, 'started_at' => now(), 'error' => null]);

        $workspace = null;
        $keepBranch = false;

        try {
            if (! $app->isScannable() || ! $this->checkouts->contains((string) $app->local_path)) {
                throw new RuntimeException("There is no checkout of {$app->host} under the scanner root on this machine.");
            }

            $targets = Surface::query()->whereIn('id', $build->targets)->where('fleet_app_id', $app->id)->get();

            if ($targets->isEmpty()) {
                throw new RuntimeException('None of the build\'s targets exist any more.');
            }

            $workspace = app(Workspace::class, ['app' => $app, 'for' => $build]);
            $revision = $workspace->resolveRevision();
            $branch = 'tests/auto-'.$build->id.'-'.Str::slug(Str::limit($targets->first()->label, 40, ''));

            $build->update(['base_ref' => $revision['ref'], 'base_sha' => $revision['sha'], 'branch' => $branch]);

            $workspace->prepare($revision['sha'], $branch);

            $build->update(['status' => BuildStatus::Writing]);
            $framework = is_file($workspace->path.'/vendor/bin/pest') ? 'pest' : 'phpunit';
            $agent = $this->agent->run($workspace, $this->prompts->build($build, $targets, $framework));

            $build->update([
                'cost_usd' => $agent['cost'],
                'agent_report' => $this->report($agent['report']),
                'log' => Str::limit($agent['report'] ?? $agent['output'], 60000, ''),
            ]);

            if (! $agent['ok']) {
                throw new RuntimeException('The agent did not finish: '.Str::limit(trim($agent['output']), 1500));
            }

            $build->update(['status' => BuildStatus::Verifying]);
            $verification = $this->verifier->verify($build, $workspace, $targets);

            $build->update([
                'verification' => $verification,
                'changed_files' => $verification['files'],
                'targets_proven' => count(array_filter($verification['targets'], fn (array $t) => $t['proven'])),
                'tests_added' => $verification['tests'],
                'notes' => array_map(fn (string $f) => "Put back a change outside tests/ and database/factories/: {$f}", $verification['reverted']) ?: null,
            ]);

            if (! $verification['accepted']) {
                $build->update([
                    'status' => BuildStatus::Rejected,
                    'error' => implode("\n", $verification['reasons']),
                    'finished_at' => now(),
                ]);

                return $build->refresh();
            }

            $build->update(['status' => BuildStatus::Publishing]);
            $published = $this->publisher->publish($build, $workspace, $verification['files'], $verification);

            $keepBranch = $published['pr_url'] === null;

            $build->update([
                'status' => $published['pr_url'] === null ? BuildStatus::Ready : BuildStatus::Opened,
                'commit_sha' => $published['commit'],
                'pr_url' => $published['pr_url'],
                'pr_number' => $published['pr_number'],
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);

            $build->update([
                'status' => BuildStatus::Failed,
                'error' => Str::limit($e->getMessage(), 5000),
                'finished_at' => now(),
            ]);
        } finally {
            $workspace?->cleanup(keepBranch: $keepBranch);
        }

        return $build->refresh();
    }

    /**
     * The JSON block the agent was asked to end with, if it did.
     *
     * @return array{tests?: list<array>, suspected_bugs?: list<array>, skipped?: list<array>}|null
     */
    public function report(?string $text): ?array
    {
        if ($text === null || ! preg_match_all('/```json\s*(\{.*?\})\s*```/s', $text, $m)) {
            return null;
        }

        $json = json_decode((string) end($m[1]), true);

        return is_array($json) ? array_intersect_key($json, array_flip(['tests', 'suspected_bugs', 'skipped'])) : null;
    }
}
