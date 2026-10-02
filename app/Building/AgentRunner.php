<?php

namespace App\Building;

use App\Scanning\Workspace;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * Runs Claude Code headless in a build's worktree to write the tests.
 *
 * The agent can read anything in the worktree, edit files, and run the
 * app's own test and lint commands — nothing else: no network tools, no MCP
 * servers, no other shell commands. Its environment is the workspace's, so
 * the tests it runs hit the build's scratch database and no real credential
 * is in reach. What it claims is recorded but never trusted; the builder
 * verifies the result itself.
 */
class AgentRunner
{
    /** The only shell commands the agent may run. */
    private const BASH = [
        'Bash(php artisan test:*)',
        'Bash(vendor/bin/pest:*)',
        'Bash(vendor/bin/phpunit:*)',
        'Bash(vendor/bin/pint:*)',
        'Bash(php artisan route:list:*)',
        'Bash(php artisan make:test:*)',
        'Bash(php artisan make:factory:*)',
        'Bash(git diff:*)',
        'Bash(git status:*)',
    ];

    /**
     * @return array{ok: bool, report: ?string, cost: ?float, output: string}
     */
    public function run(Workspace $workspace, string $prompt): array
    {
        $command = [
            (string) config('scanner.build.claude'),
            '-p', $prompt,
            '--output-format', 'json',
            '--permission-mode', 'acceptEdits',
            '--allowedTools', implode(',', ['Read', 'Edit', 'Write', 'Glob', 'Grep', ...self::BASH]),
            '--disallowedTools', 'WebFetch,WebSearch,Agent',
            '--strict-mcp-config',
            '--no-session-persistence',
        ];

        if (($model = config('scanner.build.model')) !== null && $model !== '') {
            array_push($command, '--model', (string) $model);
        }

        try {
            $result = Process::path($workspace->path)
                ->timeout((int) config('scanner.build.timeout'))
                ->env($workspace->environment())
                ->run($command);
        } catch (ProcessTimedOutException $e) {
            return ['ok' => false, 'report' => null, 'cost' => null, 'output' => 'The agent ran past the build timeout. '.$e->result->errorOutput()];
        }

        $json = json_decode($result->output(), true);

        if (! is_array($json)) {
            return ['ok' => false, 'report' => null, 'cost' => null, 'output' => trim($result->output()."\n".$result->errorOutput())];
        }

        return [
            'ok' => $result->successful() && ! ($json['is_error'] ?? false),
            'report' => is_string($json['result'] ?? null) ? $json['result'] : null,
            'cost' => isset($json['total_cost_usd']) ? (float) $json['total_cost_usd'] : null,
            'output' => $result->output(),
        ];
    }
}
