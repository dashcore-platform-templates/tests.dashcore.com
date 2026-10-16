<?php

namespace App\Console\Commands;

use App\Simulation\StripeForwarder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A client's whole signup, flown in one go against a stand-in fleet: a
 * stranger enquires and is sold the plan, the order builds their fleet, and
 * they sign in to it for the first time (`simulation.launch`).
 *
 * A countdown first, so a launch never stops halfway for something it
 * could have seen on the pad: the Stripe CLI signed in, every app of the
 * fleet answering, and the fleets checkout that builds the client's fleet
 * in place. Then each stage through `tests:journey`, which guards its own
 * apps as ever; the first that fails ends the flight, and every flight is
 * recorded under the screenshots folder's `launches/`.
 */
class Launch extends Command
{
    protected $signature = 'tests:launch
        {--target=practice : The stand-in fleet to fly it against (simulation.fleets)}';

    protected $description = "Fly a client's whole signup — enquiry to first sign-in — across a stand-in fleet";

    public function handle(StripeForwarder $stripe): int
    {
        $target = (string) $this->option('target');
        $fleet = config("simulation.fleets.{$target}");
        $stages = array_values((array) config('simulation.launch'));

        if (! is_array($fleet)) {
            $this->components->error("No stand-in fleet \"{$target}\". Known: ".implode(', ', array_keys(config('simulation.fleets', []))).'.');

            return self::FAILURE;
        }

        $flight = ['target' => $target, 'started_at' => now()->toIso8601String(), 'countdown' => [], 'stages' => []];
        $this->components->info("Countdown — {$fleet['name']}");
        $flight['countdown'] = $this->countdown($stripe, $fleet);

        foreach ($flight['countdown'] as $check) {
            $this->components->twoColumnDetail($check['check'], $check['go'] ? '<fg=green>go</>' : "<fg=red>no go</> <fg=gray>{$check['why']}</>");
        }

        if (in_array(false, array_column($flight['countdown'], 'go'), true)) {
            $this->components->error('Scrubbed on the pad: nothing was flown.');

            return $this->record($flight, 'scrubbed');
        }

        foreach ($stages as $i => $stage) {
            $this->newLine();
            $this->components->info(sprintf('Stage %d of %d — %s', $i + 1, count($stages), $stage));
            $started = microtime(true);
            $passed = $this->call('tests:journey', ['journey' => $stage, '--target' => $target]) === self::SUCCESS;
            $flight['stages'][] = ['stage' => $stage, 'passed' => $passed, 'seconds' => round(microtime(true) - $started, 1)];

            if (! $passed) {
                $this->components->error(sprintf('The launch failed at stage %d of %d, %s. What it says above is why.', $i + 1, count($stages), $stage));

                return $this->record($flight, 'failed');
            }
        }

        $this->components->info(sprintf('Launched: all %d stages flew in %.0f s.', count($stages), array_sum(array_column($flight['stages'], 'seconds'))));

        return $this->record($flight, 'launched');
    }

    /**
     * What has to hold before anything is flown.
     *
     * @param  array<string, mixed>  $fleet
     * @return list<array{check: string, go: bool, why: ?string}>
     */
    private function countdown(StripeForwarder $stripe, array $fleet): array
    {
        $checks = [[
            'check' => 'Stripe CLI signed in',
            'go' => $go = $stripe->signingSecret() !== null,
            'why' => $go ? null : 'run `stripe login` on this machine',
        ]];

        foreach ((array) $fleet['apps'] as $key => $app) {
            $url = rtrim((string) $app['base_url'], '/').'/up';

            try {
                $status = Http::timeout(10)->get($url)->status();
            } catch (Throwable $e) {
                $status = null;
                $error = str($e->getMessage())->limit(120)->toString();
            }

            $checks[] = [
                'check' => "{$key} answers",
                'go' => $go = $status !== null && $status < 400,
                'why' => $go ? null : ($status === null ? "{$url}: ".($error ?? 'no answer') : "{$url} answered {$status}"),
            ];
        }

        $checkout = (string) ($fleet['fleets_checkout'] ?? '');
        $checks[] = [
            'check' => 'fleets can build the client\'s fleet',
            'go' => $go = $checkout !== '' && is_file("{$checkout}/artisan"),
            'why' => $go ? null : "no fleets checkout at {$checkout}",
        ];

        return $checks;
    }

    /**
     * @param  array<string, mixed>  $flight
     */
    private function record(array $flight, string $outcome): int
    {
        $flight = [...$flight, 'outcome' => $outcome, 'finished_at' => now()->toIso8601String()];
        $path = rtrim((string) config('simulation.screenshots'), '/').'/launches/'.now()->format('Ymd-His').'.json';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($flight, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->line("  <fg=gray>Recorded: {$path}</>");

        return $outcome === 'launched' ? self::SUCCESS : self::FAILURE;
    }
}
