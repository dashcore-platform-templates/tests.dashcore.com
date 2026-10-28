<?php

namespace App\Console\Commands;

use App\Models\Launch as LaunchRecord;
use App\Simulation\StripeForwarder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A client's whole signup, flown in one go against a stand-in fleet: a
 * stranger enquires and is sold the plan, the order builds their fleet,
 * they sign in to it for the first time and find their first briefing, and
 * Dashcore holds their onboarding call (`simulation.launch`). It is the
 * client journey map's source: the map draws the latest flight.
 *
 * A countdown first, so a launch never stops halfway for something it
 * could have seen on the pad: the Stripe CLI signed in, every app of the
 * fleet answering, and the fleets checkout that builds the client's fleet
 * in place. Then each stage through `tests:journey --record`, which guards
 * its own apps as ever; the first that fails ends the flight. Once a fleet
 * was built it is taken down again (`simulation.launch_teardown`), so the
 * nightly flight leaves nothing behind. Every flight is kept as a Launch,
 * and as JSON under the screenshots folder's `launches/`.
 */
class Launch extends Command
{
    protected $signature = 'tests:launch
        {--target= : The stand-in fleet to fly it against (simulation.fleets; default simulation.fleet)}';

    protected $description = "Fly a client's whole signup — enquiry to the onboarding call — across a stand-in fleet";

    public function handle(StripeForwarder $stripe): int
    {
        $target = (string) ($this->option('target') ?? config('simulation.fleet'));
        $fleet = config("simulation.fleets.{$target}");
        $stages = array_values((array) config('simulation.launch'));

        if (! is_array($fleet)) {
            $this->components->error("No stand-in fleet \"{$target}\". Known: ".implode(', ', array_keys(config('simulation.fleets', []))).'.');

            return self::FAILURE;
        }

        $flight = ['target' => $target, 'started_at' => now()->toIso8601String(), 'countdown' => [], 'stages' => [], 'teardown' => []];
        $records = rtrim((string) config('simulation.screenshots'), '/').'/launches/'.now()->format('Ymd-His');
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
            $flight['stages'][] = $this->fly($stage, $target, $records);

            if (! end($flight['stages'])['passed']) {
                $this->components->error(sprintf('The launch failed at stage %d of %d, %s. What it says above is why.', $i + 1, count($stages), $stage));
                $flight['teardown'] = $this->teardown($flight, $target, $records);

                return $this->record($flight, 'failed');
            }
        }

        $this->components->info(sprintf('Launched: all %d stages flew in %.0f s.', count($stages), array_sum(array_column($flight['stages'], 'seconds'))));
        $flight['teardown'] = $this->teardown($flight, $target, $records);

        return $this->record($flight, 'launched');
    }

    /**
     * One stage, through `tests:journey`, and what it recorded.
     *
     * @return array<string, mixed>
     */
    private function fly(string $stage, string $target, string $records): array
    {
        $record = "{$records}/{$stage}.json";
        $started = microtime(true);
        $passed = $this->call('tests:journey', ['journey' => $stage, '--target' => $target, '--record' => $record]) === self::SUCCESS;
        $recorded = is_file($record) ? (array) json_decode((string) File::get($record), true) : [];

        return [...$recorded, 'stage' => $stage, 'passed' => $passed, 'seconds' => round(microtime(true) - $started, 1)];
    }

    /**
     * Once the client's fleet was built, take it down again: the stages in
     * `simulation.launch_teardown` (the customer cancels), so a nightly
     * launch does not leave a practice fleet behind each night. Nothing is
     * flown when the build never ran.
     *
     * @param  array<string, mixed>  $flight
     * @return list<array<string, mixed>>
     */
    private function teardown(array $flight, string $target, string $records): array
    {
        $built = collect($flight['stages'])->contains(fn (array $stage) => $stage['stage'] === 'provisioning' && $stage['passed']);
        $teardown = [];

        if (! $built) {
            return $teardown;
        }

        foreach ((array) config('simulation.launch_teardown') as $stage) {
            $this->newLine();
            $this->components->info("Teardown — {$stage}");
            $teardown[] = $this->fly($stage, $target, $records);
        }

        return $teardown;
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
        LaunchRecord::query()->create([
            'target' => $flight['target'],
            'outcome' => $outcome,
            'countdown' => $flight['countdown'],
            'stages' => $flight['stages'],
            'teardown' => $flight['teardown'],
            'started_at' => $flight['started_at'],
            'finished_at' => $flight['finished_at'],
        ]);
        $path = rtrim((string) config('simulation.screenshots'), '/').'/launches/'.now()->format('Ymd-His').'.json';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($flight, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->line("  <fg=gray>Recorded: {$path}</>");

        return $outcome === 'launched' ? self::SUCCESS : self::FAILURE;
    }
}
