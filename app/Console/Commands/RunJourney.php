<?php

namespace App\Console\Commands;

use App\Simulation\CustomerJourney;
use App\Simulation\JourneyResult;
use App\Simulation\JourneyWalker;
use App\Simulation\Persona;
use App\Simulation\Script;
use App\Simulation\Simulation;
use App\Simulation\StandInGuard;
use App\Simulation\StripeForwarder;
use Faker\Factory as Faker;
use Faker\Generator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Walks one stage of the customer journey across a stand-in fleet and says,
 * step by step, whether it held. With no journey, lists every stage and
 * which are still pending — the progress bar for the whole journey.
 *
 * Nothing is driven until every app the journey touches is a stand-in and
 * has shown that its mail stays in its log — and, where it takes payments,
 * that it is on its provider's test keys. A journey that pays is walked
 * only while the Stripe CLI carries its webhooks to the app that is paid.
 */
class RunJourney extends Command
{
    protected $signature = 'tests:journey
        {journey? : The customer journey to walk (e.g. enquiry); omit to list them}
        {--target=practice : The stand-in fleet to walk it against (simulation.fleets)}
        {--list : List the customer journeys and which are pending}';

    protected $description = 'Walk a customer journey across a stand-in fleet, step by step';

    public function handle(Simulation $simulation, StandInGuard $guard, JourneyWalker $walker, StripeForwarder $stripe): int
    {
        $key = $this->argument('journey');

        if ($key === null || $this->option('list')) {
            return $this->list();
        }

        $stage = config("simulation.customer_journeys.{$key}");

        if (! is_array($stage)) {
            $this->components->error("No customer journey \"{$key}\". Known: ".implode(', ', array_keys(config('simulation.customer_journeys', []))).'.');

            return self::FAILURE;
        }

        if (empty($stage['journey'])) {
            $this->components->warn("{$stage['name']} is pending: {$stage['what']}");

            return self::FAILURE;
        }

        $target = (string) $this->option('target');
        $fleet = config("simulation.fleets.{$target}");

        if (! is_array($fleet)) {
            $this->components->error("No stand-in fleet \"{$target}\". Known: ".implode(', ', array_keys(config('simulation.fleets', []))).'.');

            return self::FAILURE;
        }

        /** @var CustomerJourney $journey */
        $journey = app($stage['journey']);
        $faker = Faker::create();

        // The apps are read off the script itself, so what is guarded is
        // exactly what will be driven. The people are placeholders until the
        // guard has passed; nobody is made in an app that might mail them.
        $placeholders = array_map(fn () => new Persona('Someone', 'someone@example.com', '', ''), $journey->signsIn());
        // The app it pays is guarded too, even if no step visits it.
        // Apps its own prepare() brings into being are guarded once they exist.
        $apps = array_values(array_diff(
            array_unique([...$journey->script($placeholders, Faker::create())->apps(), ...array_keys($journey->signsIn()), ...$journey->guards(), ...array_filter([$journey->stripeWebhookApp()])]),
            $journey->providesApps(),
        ));

        $this->components->info("{$stage['name']}: {$journey->name()} — on {$fleet['name']}");

        if (! $this->guardApps($guard, $key, $fleet, $apps, $journey->requires())) {
            return self::FAILURE;
        }

        $forwarding = null;

        if (($paid = $journey->stripeWebhookApp()) !== null) {
            $forwarding = $this->forwardStripe($stripe, $guard, $key, $paid, $fleet['apps'][$paid] ?? null, $journey->stripeEvents());

            if ($forwarding === null) {
                return self::FAILURE;
            }
        }

        try {
            $fleet = $this->prepare($guard, $key, $fleet, $journey);

            if ($fleet === null) {
                return self::FAILURE;
            }

            return $this->walk($simulation, $walker, $key, $stage, $fleet, $journey, $faker, $apps);
        } finally {
            $stripe->stop($forwarding);
        }
    }

    /**
     * Every app named, a stand-in whose mail stays in its log (and, where it
     * takes payments, on test keys) — or false, having said which is not.
     *
     * @param  array<string, mixed>  $fleet
     * @param  list<string>  $apps
     * @param  array<string, array<string, mixed>>  $requires  config each app must hold (CustomerJourney::requires)
     */
    private function guardApps(StandInGuard $guard, string $key, array $fleet, array $apps, array $requires = []): bool
    {
        foreach ($apps as $app) {
            $config = $fleet['apps'][$app] ?? null;

            if (! is_array($config)) {
                $this->components->error("{$fleet['name']} has no {$app} app; the journey needs it.");

                return false;
            }

            $testMode = (array) ($config['test_mode'] ?? []);

            $refusal = $guard->checkoutRefusal($config['checkout'] ?? null)
                ?? $guard->mailRefusal($config['checkout'], (array) ($config['mail_guard'] ?? []))
                ?? ($testMode === [] ? null : $guard->testModeRefusal($config['checkout'], $testMode))
                ?? $guard->settingRefusal($config['checkout'], (array) ($requires[$app] ?? []));

            if ($refusal !== null) {
                $this->components->error("Refusing to walk {$key} against {$app}: {$refusal}");

                return false;
            }

            $this->components->twoColumnDetail("{$app} <fg=gray>{$config['base_url']}</>", '<fg=green>stand-in, mail to log'.($testMode === [] ? '' : ', payments in test mode').'</>');
        }

        return true;
    }

    /**
     * The journey's work on this machine, then the apps it brought into
     * being guarded like the rest: the fleet to walk, or null having said
     * why not.
     *
     * @param  array<string, mixed>  $fleet
     * @return array<string, mixed>|null
     */
    private function prepare(StandInGuard $guard, string $key, array $fleet, CustomerJourney $journey): ?array
    {
        try {
            $provided = $journey->prepare($fleet, fn (string $line) => $this->line("  <fg=gray>{$line}</>"));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return null;
        }

        $missing = array_diff($journey->providesApps(), array_keys($provided));

        if ($missing !== []) {
            $this->components->error('The journey did not say where '.implode(', ', $missing).' is.');

            return null;
        }

        $fleet['apps'] = [...$fleet['apps'], ...$provided];

        return $this->guardApps($guard, $key, $fleet, array_keys($provided), $journey->requires()) ? $fleet : null;
    }

    /**
     * Make the people, walk the script — and each one the journey has next
     * (CustomerJourney::next) — and report it.
     *
     * @param  array<string, mixed>  $stage
     * @param  array<string, mixed>  $fleet
     * @param  list<string>  $guarded  the apps of the stand-in guarded before prepare()
     */
    private function walk(Simulation $simulation, JourneyWalker $walker, string $key, array $stage, array $fleet, CustomerJourney $journey, Generator $faker, array $guarded): int
    {
        $people = [];

        foreach ($journey->signsIn() as $app => $role) {
            $person = $this->person($simulation, $app, $fleet['apps'][$app], $role);

            if ($person === null) {
                return self::FAILURE;
            }

            $people[$app] = $person;
        }

        $guarded = [...$guarded, ...$journey->providesApps()];
        $script = $journey->script($people, $faker);
        $started = now()->format('Ymd-His');
        $steps = 0;
        $ms = 0;

        for ($walk = 1; ; $walk++) {
            $unguarded = array_diff($script->apps(), $guarded);

            if ($unguarded !== []) {
                $this->components->error("{$stage['name']}'s walk {$walk} drives ".implode(', ', $unguarded).', which nothing guarded. Name it in guards().');

                return self::FAILURE;
            }

            $result = $this->walkOnce($walker, $stage['name'], $script, $fleet, rtrim((string) config('simulation.screenshots'), '/')."/journeys/{$key}-{$started}".($walk > 1 ? "-{$walk}" : ''));

            if ($result === null) {
                return self::FAILURE;
            }

            $steps += $result->total;
            $ms += array_sum(array_column($result->steps, 'ms'));
            $summary = $result->interpolate((string) $script->getSummary());

            try {
                $script = $journey->next($fleet, $people, $faker, fn (string $line) => $this->line("  <fg=gray>{$line}</>"));
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            if ($script === null) {
                break;
            }
        }

        $this->components->info(sprintf('%s passed: %d steps in %.1f s. %s', $stage['name'], $steps, $ms / 1000, $summary));

        return self::SUCCESS;
    }

    /**
     * One script walked and reported step by step: its result, or null
     * having said how it failed.
     *
     * @param  array<string, mixed>  $fleet
     */
    private function walkOnce(JourneyWalker $walker, string $name, Script $script, array $fleet, string $shots): ?JourneyResult
    {
        $this->newLine();

        $result = $walker->walk(
            $script,
            array_map(fn (array $app) => (string) $app['base_url'], $fleet['apps']),
            $shots,
            function (array $step) {
                $mark = $step['status'] === 'passed' ? '<fg=green>✓</>' : '<fg=red>✗</>';
                $this->line(sprintf('  %s %s <fg=gray>%s · %s ms</>', $mark, $step['label'], $step['on'] ?? '-', $step['ms'] ?? '?'));

                if ($step['status'] !== 'passed') {
                    foreach (array_filter([$step['error'], $step['url'] ? "at {$step['url']}" : null]) as $detail) {
                        $this->line('      <fg=red>'.str_replace("\n", "\n      ", $detail).'</>');
                    }
                }
            },
            // A stand-in's mail is in its log; `mail` steps read it there.
            array_map(fn (array $app) => rtrim((string) $app['checkout'], '/').'/storage/logs', array_filter($fleet['apps'], fn (array $app) => ! empty($app['checkout']))),
        );

        foreach (array_slice($script->steps(), count($result->steps)) as $step) {
            $this->line('  <fg=gray>- '.$result->interpolate($step['label']).' (not reached)</>');
        }

        $this->newLine();

        foreach (array_unique($result->serverErrors) as $error) {
            $this->components->warn("Server error: {$error}");
        }

        if ($result->error !== null) {
            $this->components->error($result->error);
        }

        if (! $result->passed()) {
            $this->components->error(sprintf('%s failed after %d of %d steps. Screenshots: %s', $name, count(array_filter($result->steps, fn ($s) => $s['status'] === 'passed')), $result->total, $result->shotsDir));

            return null;
        }

        return $result;
    }

    /**
     * Start the Stripe CLI forwarding test events to the app a journey pays,
     * once it is safe to: the app has a webhook to forward to, the CLI is
     * signed in, and the app checks events with the CLI's own signing
     * secret — compared by fingerprint, neither printed. Its test keys were
     * checked with the rest of the guard. Null, having said why, when not.
     *
     * @param  array<string, mixed>|null  $app
     * @param  list<string>  $events
     */
    private function forwardStripe(StripeForwarder $stripe, StandInGuard $guard, string $key, string $paid, ?array $app, array $events): ?InvokedProcess
    {
        $webhook = (array) ($app['stripe_webhook'] ?? []);

        if ($app === null || empty($app['test_mode']) || empty($webhook['path']) || empty($webhook['secret'])) {
            $this->components->error("Refusing to walk {$key}: {$paid} has no Stripe test-mode check and webhook (test_mode, stripe_webhook) on its fleet entry.");

            return null;
        }

        $secret = $stripe->signingSecret();

        if ($secret === null) {
            $this->components->error("Refusing to walk {$key}: the Stripe CLI is not signed in (run `stripe login`), so no payment would reach {$paid}.");

            return null;
        }

        $holds = $guard->holdsSecret((string) $app['checkout'], (string) $webhook['secret'], $secret);

        if ($holds !== true) {
            $this->components->error($holds === null
                ? "Refusing to walk {$key}: could not read {$paid}'s Stripe webhook secret ({$webhook['secret']})."
                : "Refusing to walk {$key}: {$paid}'s {$webhook['secret']} is not the Stripe CLI's signing secret (`stripe listen --print-secret`), so it would refuse every forwarded event.");

            return null;
        }

        $url = rtrim((string) $app['base_url'], '/').'/'.ltrim((string) $webhook['path'], '/');

        try {
            $process = $stripe->start($url, $events);
        } catch (Throwable $e) {
            $this->components->error("Could not forward Stripe's webhooks to {$paid}: {$e->getMessage()}");

            return null;
        }

        $this->components->twoColumnDetail("stripe listen <fg=gray>→ {$url}</>", '<fg=green>forwarding test events</>');

        return $process;
    }

    private function list(): int
    {
        $stages = config('simulation.customer_journeys', []);
        $walked = count(array_filter($stages, fn (array $stage) => ! empty($stage['journey'])));

        $this->components->info(sprintf('The customer journey: %d of %d stages simulated.', $walked, count($stages)));

        foreach ($stages as $key => $stage) {
            $this->components->twoColumnDetail(
                "{$key} <fg=gray>{$stage['name']}</>",
                empty($stage['journey']) ? '<fg=yellow>pending</>' : '<fg=green>ready</> <fg=gray>'.app($stage['journey'])->name().'</>',
            );
        }

        return self::SUCCESS;
    }

    /**
     * The person who signs in to an app: its persona in that role, made or
     * refreshed by the app's own command with a password for this run only,
     * when the app names one; else the persona as its seeders made it.
     *
     * @param  array<string, mixed>  $app
     */
    private function person(Simulation $simulation, string $key, array $app, string $role): ?Persona
    {
        $persona = collect($app['personas'] ?? [])->firstWhere('role', $role);

        if ($persona === null) {
            $this->components->error("{$key} has no persona in the role {$role}.");

            return null;
        }

        if (empty($app['persona_command'])) {
            return new Persona($persona['name'], $persona['email'], (string) ($app['password'] ?? 'password'), $role);
        }

        $password = Str::password(24, symbols: false);
        $arguments = array_map(fn (string $part) => strtr($part, [
            '{email}' => $persona['email'],
            '{name}' => $persona['name'],
            '{password}' => $password,
        ]), $app['persona_command']);

        $result = Process::path($app['checkout'])
            ->env($simulation->cleanEnvironment())
            ->timeout(120)
            ->run(['php', 'artisan', ...$arguments, '--no-interaction']);

        if (! $result->successful()) {
            // Its output, never the command: that carries the password.
            $this->components->error("Could not make {$persona['name']} in {$key}: ".Str::limit(trim($result->errorOutput() ?: $result->output()), 500));

            return null;
        }

        $this->components->twoColumnDetail("{$key} <fg=gray>signs in as</>", "{$persona['name']} <fg=gray>{$persona['email']}</>");

        return new Persona($persona['name'], $persona['email'], $password, $role);
    }
}
