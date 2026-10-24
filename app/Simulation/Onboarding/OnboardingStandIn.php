<?php

namespace App\Simulation\Onboarding;

use App\Simulation\Simulation;
use App\Simulation\StandIns\AnthropicStandIn;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What the onboarding call stage does outside the browser, through each
 * app's own commands, run in its checkout under an empty environment:
 *
 *   - fleets prints the new client fleet exactly as it reports fleets to
 *     onboarding (`fleet:report-onboarding --json`), and the practice home
 *     fleet's onboarding — a stand-in fleets cannot reach — keeps it
 *     (`onboarding:receive-fleets`);
 *   - the call with Gabe is held from a script (`avatar:scripted-call`), its
 *     answers drafted by onboarding's own model call, pointed at the
 *     Anthropic stand-in;
 *   - the held session is read as fleets reads it (`onboarding:fleet-session`)
 *     and imported into the fleet's Brief (`fleet:onboarding-import`), then
 *     delivered to its apps (`fleet:deliver-brief`), as staff do on the Brief
 *     tab.
 */
class OnboardingStandIn
{
    public function __construct(private Simulation $simulation) {}

    /** fleets' report of one fleet, handed to the stand-in onboarding. */
    public function report(string $fleets, string $onboarding, string $install): void
    {
        $report = $this->run($fleets, ['fleet:report-onboarding', '--json', "--install={$install}"]);
        $file = $this->file('fleets', $report);

        $this->run($onboarding, ['onboarding:receive-fleets', $file]);
    }

    /**
     * The fleet's latest session, as fleets reads it.
     *
     * @return array<string, mixed>
     */
    public function session(string $onboarding, string $install): array
    {
        $said = $this->run($onboarding, ['onboarding:fleet-session', $install]);
        $session = json_decode(substr($said, (int) strpos($said, '{')), true)['session'] ?? null;

        if (! is_array($session)) {
            throw new RuntimeException("onboarding has no session for {$install} yet.");
        }

        return $session;
    }

    /**
     * Holds the call from its script and drafts its answers against the
     * stand-in. Returns what the command said of it.
     *
     * @param  list<array<string, string>>  $lines  as the worker reports them
     * @return array{call: int, lines: int, answers_drafted: bool, proposed: int}
     */
    public function holdCall(string $onboarding, int $session, string $host, array $lines, AnthropicStandIn $model): array
    {
        $said = $this->run($onboarding, ['avatar:scripted-call', (string) $session, $this->file('call', ['lines' => $lines]), "--host={$host}", '--draft'], $model->env());
        $result = json_decode(substr($said, (int) strpos($said, '{')), true);

        if (! is_array($result) || ! isset($result['call'])) {
            throw new RuntimeException("onboarding did not say how the call went: {$said}");
        }

        return $result;
    }

    /**
     * Imports the held session into the fleet's Brief, and delivers it.
     *
     * @param  array<string, mixed>  $session
     * @return array{imported: string, delivered: string}
     */
    public function importAndDeliver(string $fleets, string $install, array $session): array
    {
        return [
            'imported' => $this->run($fleets, ['fleet:onboarding-import', $install, $this->file('session', ['session' => $session]), '--by=Proving Ground']),
            'delivered' => $this->run($fleets, ['fleet:deliver-brief', $install], timeout: 600),
        ];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function run(string $checkout, array $command, array $env = [], int $timeout = 120): string
    {
        if (! is_file("{$checkout}/artisan")) {
            throw new RuntimeException("There is no checkout at {$checkout}.");
        }

        $result = Process::path($checkout)
            ->env([...$this->simulation->cleanEnvironment(), ...$env])
            ->timeout($timeout)
            ->run(['php', 'artisan', ...$command, '--no-interaction']);

        $said = trim($result->output());

        if (! $result->successful()) {
            throw new RuntimeException("`{$command[0]}` in {$checkout} failed: ".Str::limit(trim($result->errorOutput() ?: $said), 600));
        }

        return $said;
    }

    /** @param  array<string, mixed>|string  $content */
    private function file(string $name, array|string $content): string
    {
        $path = storage_path('app/onboarding-stand-in/'.now()->format('Ymd-His')."-{$name}-".Str::lower(Str::random(6)).'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, is_string($content) ? substr($content, (int) strpos($content, '{')) : (string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
