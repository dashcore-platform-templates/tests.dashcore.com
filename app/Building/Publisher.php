<?php

namespace App\Building;

use App\Models\Build;
use App\Scanning\Workspace;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Commits a verified build's tests on its branch and, when publishing is on,
 * pushes it and opens a PR against the app's default branch for review. Only
 * the files verification kept are committed — never the workspace's own
 * rewritten phpunit.xml or .env.
 */
class Publisher
{
    /**
     * @param  list<string>  $files
     * @param  array<string, mixed>  $verification
     * @return array{commit: string, pr_url: ?string, pr_number: ?int}
     */
    public function publish(Build $build, Workspace $workspace, array $files, array $verification): array
    {
        $git = fn (array $args, int $timeout = 120) => Process::path($workspace->path)->timeout($timeout)->run(['git', ...$args]);

        $add = $git(['add', '--', ...$files]);
        if ($add->failed()) {
            throw new RuntimeException('git add failed: '.$add->errorOutput());
        }

        $commit = $git(['commit', '--no-verify', '-m', $this->commitMessage($build, $verification)]);
        if ($commit->failed()) {
            throw new RuntimeException('git commit failed: '.trim($commit->errorOutput() ?: $commit->output()));
        }

        $sha = trim($git(['rev-parse', 'HEAD'])->output());

        if (! config('scanner.build.publish')) {
            return ['commit' => $sha, 'pr_url' => null, 'pr_number' => null];
        }

        $push = $git(['push', '--set-upstream', 'origin', (string) $workspace->branch], 300);
        if ($push->failed()) {
            throw new RuntimeException('git push failed: '.trim($push->errorOutput()));
        }

        $bodyFile = $workspace->runDir.'/pr-body.md';
        file_put_contents($bodyFile, $this->body($build, $verification));

        $repo = $build->fleetApp->repo;
        $pr = Process::path($workspace->path)->timeout(120)->run(array_values(array_filter([
            'gh', 'pr', 'create',
            $repo ? '--repo' : null, $repo,
            '--base', Str::after((string) $build->base_ref, 'origin/'),
            '--head', (string) $workspace->branch,
            '--title', $this->title($build, $verification),
            '--body-file', $bodyFile,
        ], fn ($v) => $v !== null)));

        if ($pr->failed()) {
            throw new RuntimeException('The branch was pushed but the PR could not be opened: '.trim($pr->errorOutput()));
        }

        $url = trim(collect(explode("\n", trim($pr->output())))->last());

        return [
            'commit' => $sha,
            'pr_url' => $url,
            'pr_number' => preg_match('#/pull/(\d+)#', $url, $m) ? (int) $m[1] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $verification
     */
    private function title(Build $build, array $verification): string
    {
        $proven = collect($verification['targets'])->where('proven', true);
        $first = $proven->first()['label'] ?? 'gaps';

        return Str::limit('Add tests for '.$first.($proven->count() > 1 ? ' and '.($proven->count() - 1).' more' : ''), 70, '…');
    }

    /**
     * @param  array<string, mixed>  $verification
     */
    private function commitMessage(Build $build, array $verification): string
    {
        $lines = collect($verification['targets'])
            ->where('proven', true)
            ->map(fn (array $t) => '- '.$t['label'])
            ->implode("\n");

        return $this->title($build, $verification)."\n\n"
            ."Written by an agent for tests.dashcore.com build #{$build->id} and verified\n"
            ."there: each test passes, asserts, reaches its target and fails when the\n"
            ."target's body is gutted.\n\n"
            .$lines."\n\n"
            .'Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>';
    }

    /**
     * @param  array<string, mixed>  $verification
     */
    private function body(Build $build, array $verification): string
    {
        $rows = collect($verification['targets'])->map(fn (array $t) => sprintf(
            '| %s | %s | %s | %s |',
            str_replace('|', '\|', $t['label']),
            $t['proven'] ? '✅ proven' : '❌ not proven',
            match ($t['mutation']) {
                'killed' => 'caught',
                'survived' => 'survived',
                'error' => 'inconclusive',
                default => 'n/a',
            },
            str_replace('|', '\|', implode('; ', array_slice($t['tests'], 0, 3))),
        ))->implode("\n");

        $bugs = collect($build->agent_report['suspected_bugs'] ?? [])
            ->map(fn (array $b) => '- '.($b['target'] ?? '?').': '.($b['description'] ?? ''))
            ->implode("\n");

        $skipped = collect($build->agent_report['skipped'] ?? [])
            ->map(fn (array $b) => '- '.($b['target'] ?? '?').': '.($b['reason'] ?? ''))
            ->implode("\n");

        $files = collect($verification['files'])->map(fn (string $f) => '- `'.$f.'`')->implode("\n");

        return <<<MD
            Tests for surfaces tests.dashcore.com found untested or unguarded in **{$build->fleetApp->name}**.

            An agent wrote these tests; tests.dashcore.com then verified them on its own: the new tests pass and assert, the probe confirmed each target is exercised, each target's body was swapped for a trivial default and a test had to fail, and the full suite has no new failures.

            | Target | Verdict | Mutation | Proven by |
            |---|---|---|---|
            {$rows}

            **Files**
            {$files}

            MD
            .($bugs !== '' ? "\n**Suspected bugs (left untested, not changed)**\n{$bugs}\n" : '')
            .($skipped !== '' ? "\n**Left out by the agent**\n{$skipped}\n" : '')
            ."\nBased on `".substr((string) $build->base_sha, 0, 7)."`. Build #{$build->id}.\n\n🤖 Generated with [Claude Code](https://claude.com/claude-code)\n";
    }
}
