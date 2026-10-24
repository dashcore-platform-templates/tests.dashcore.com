<?php

namespace App\Simulation\Provisioning;

use App\Simulation\Simulation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What the provisioning journeys do outside the browser: ask a practice
 * fleet's dcos what has been paid for, have fleets.dashcore.com build it,
 * and read back from dcos where it was built.
 *
 * Each is the app's own artisan command, run in its checkout under an empty
 * environment (Simulation::cleanEnvironment), so it reads its own .env and
 * never this app's. dcos is the ledger: fleets reports into it, and this
 * reads the report there rather than asking fleets — the client is told
 * their address by dcos, so that is the copy worth checking.
 *
 * Rehearsal only. A source that is not a practice fleet is refused before
 * anything runs, and a client fleet that came up anywhere but under Herd
 * here, in a *.practice folder, is refused before a browser goes near it.
 */
class Provisioner
{
    public function __construct(private Simulation $simulation) {}

    /**
     * @param  array<string, mixed>  $fleet  a stand-in fleet (simulation.fleets.*)
     *
     * @throws RuntimeException when the fleet is not a practice fleet
     */
    public function source(array $fleet): string
    {
        $source = (string) ($fleet['install'] ?? '');

        if (! str_starts_with($source, 'practice-')) {
            throw new RuntimeException("{$fleet['name']} is not a practice fleet (its install is ".json_encode($source).'); a client fleet is only ever built here from practice.');
        }

        return $source;
    }

    /**
     * The fleets clients have paid for that nobody has started building,
     * oldest first.
     *
     * @param  array<string, mixed>  $delivery  the fleet's dcos entry
     * @return list<array<string, mixed>>
     */
    public function pending(array $delivery): array
    {
        $listed = $this->json($delivery, ['installs:pending', '--json']);

        if (! is_array($listed['installs'] ?? null)) {
            throw new RuntimeException('dcos did not list its pending installs.');
        }

        return array_values($listed['installs']);
    }

    /**
     * One request as dcos last heard of it — the newest when no uuid is given.
     *
     * @param  array<string, mixed>  $delivery
     * @return array<string, mixed>
     */
    public function show(array $delivery, ?string $uuid = null): array
    {
        return $this->json($delivery, array_values(array_filter(['installs:show', $uuid, '--json'])));
    }

    /**
     * Have fleets take in what the source fleet's dcos sold, and build it.
     * Its output is passed on line by line: a build takes minutes, and a
     * silent one reads as a hang.
     *
     * @param  callable(string): void  $say
     */
    public function intake(string $fleetsCheckout, string $source, int $timeout, callable $say): bool
    {
        return $this->fleets($fleetsCheckout, ['fleet:intake', $source], $timeout, $say);
    }

    /**
     * One of fleets' own commands, in its checkout, its output passed on
     * line by line.
     *
     * @param  list<string>  $command
     * @param  callable(string): void  $say
     */
    private function fleets(string $fleetsCheckout, array $command, int $timeout, callable $say): bool
    {
        if (! is_dir($fleetsCheckout) || ! is_file("{$fleetsCheckout}/artisan")) {
            throw new RuntimeException("There is no fleets.dashcore.com checkout at {$fleetsCheckout}.");
        }

        return Process::path($fleetsCheckout)
            ->env($this->simulation->cleanEnvironment())
            ->timeout($timeout)
            ->start(['php', 'artisan', ...$command, '--no-interaction'], function (string $type, string $output) use ($say): void {
                foreach (preg_split('/\R/', rtrim($output)) ?: [] as $line) {
                    if (trim($line) !== '') {
                        $say($line);
                    }
                }
            })
            ->wait()
            ->successful();
    }

    /**
     * Have fleets move one app of a client fleet to a release and deploy it
     * (`fleet:push`), which succeeds only once the app is found running that
     * release's commit. Its output is passed on, as intake's is.
     *
     * @param  callable(string): void  $say
     */
    public function push(string $fleetsCheckout, string $install, string $app, string $release, int $timeout, callable $say): bool
    {
        return $this->fleets($fleetsCheckout, ['fleet:push', $install, $app, $release], $timeout, $say);
    }

    /**
     * Have fleets take in what the source fleet's dcos says was cancelled
     * (`fleet:offboard-intake`): a practice sale is taken down there and
     * then, and reported offboarded.
     *
     * @param  callable(string): void  $say
     */
    public function offboard(string $fleetsCheckout, string $source, int $timeout, callable $say): bool
    {
        return $this->fleets($fleetsCheckout, ['fleet:offboard-intake', $source], $timeout, $say);
    }

    /**
     * Whether an app still answers at its address: any successful page.
     * A site Herd no longer has a folder for answers 404, and a refused
     * connection is an answer too — that nothing is there.
     */
    public function answers(string $url): bool
    {
        try {
            return Http::timeout(10)->get(rtrim($url, '/').'/login')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * Have the cockpit look its release up again now (`install:check-release
     * --fresh`), rather than show a lookup cached from before it moved —
     * the daily schedule's job, done for the journey.
     *
     * @param  array<string, mixed>  $cockpit  a fleet entry, as cockpit() made it
     *
     * @throws RuntimeException when the cockpit could not be asked
     */
    public function checkRelease(array $cockpit): string
    {
        $result = Process::path((string) $cockpit['checkout'])
            ->env($this->simulation->cleanEnvironment())
            ->timeout(60)
            ->run(['php', 'artisan', 'install:check-release', '--fresh', '--no-interaction']);

        if (! $result->successful()) {
            throw new RuntimeException("`install:check-release` in {$cockpit['checkout']} failed: ".trim($result->errorOutput() ?: $result->output()));
        }

        return trim($result->output());
    }

    /**
     * The client's cockpit (their ceo) as a fleet entry the walker can drive.
     *
     * @param  array<string, mixed>  $request  as installs:show printed it
     * @return array{name: string, base_url: string, checkout: string}
     *
     * @throws RuntimeException when it was not built as a local practice fleet
     */
    public function cockpit(array $request, string $root): array
    {
        return $this->clientApp($request, $root, 'ceo', "{$request['company']}'s cockpit");
    }

    /**
     * Another app of the client's fleet — their login, say — as a fleet
     * entry, found beside the cockpit: the same name with its own label.
     *
     * @param  array<string, mixed>  $request  as installs:show printed it
     * @return array{name: string, base_url: string, checkout: string}
     *
     * @throws RuntimeException when it was not built as a local practice fleet
     */
    public function clientApp(array $request, string $root, string $app, ?string $name = null): array
    {
        $url = (string) ($request['install_url'] ?? '');
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (($request['status'] ?? null) !== 'built' || $host === '') {
            throw new RuntimeException("{$request['company']}'s fleet is not built: dcos has it ".($request['status'] ?? 'unknown').(empty($request['message']) ? '.' : " — {$request['message']}"));
        }

        $host = $app.'.'.Str::after($host, '.');
        $folder = $this->practiceFolder($host);

        // A local practice fleet's app lives in the Herd folder named for it:
        // <app>.<company>.practice, whichever name it answers on.
        if ($folder === null || ! is_dir(rtrim($root, '/')."/{$folder}")) {
            throw new RuntimeException("{$request['company']}'s fleet answers at {$url}, which is not a practice fleet on this machine. Nothing is driven outside one.");
        }

        return [
            'name' => $name ?? "{$request['company']}'s {$app}",
            'base_url' => (string) preg_replace('#^(https?://)[^/]+#', '${1}'.$host, rtrim($url, '/')),
            'checkout' => rtrim($root, '/')."/{$folder}",
        ];
    }

    /**
     * Whether an app's mail log holds a message to this address carrying a
     * link with this path — login's "Set up your sign-in", say. A stand-in's
     * mail is written to its log, so this is its inbox.
     *
     * @param  array{checkout: string}  $app
     */
    public function mailed(array $app, string $email, string $path): bool
    {
        foreach (glob(rtrim((string) $app['checkout'], '/').'/storage/logs/*.log') ?: [] as $log) {
            $content = (string) @file_get_contents($log);

            if (str_contains($content, $path) && (str_contains($content, $email) || str_contains($content, rawurlencode($email)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Herd folder of a practice app by the name it answers on: its .test
     * name (ceo.acme.practice.test), or its name in fleets' practice zone
     * once fleets publishes practice fleets on the tailnet
     * (ceo.acme.practice.dashcore.com). Null for any other name.
     */
    private function practiceFolder(string $host): ?string
    {
        if (str_ends_with($host, '.practice.test')) {
            return substr($host, 0, -strlen('.test'));
        }

        $zone = '.'.trim((string) config('simulation.practice_zone'), '.');

        if ($zone !== '.' && str_ends_with($host, $zone)) {
            return substr($host, 0, -strlen($zone)).'.practice';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $app
     * @param  list<string>  $command
     * @return array<string, mixed>
     */
    private function json(array $app, array $command): array
    {
        $result = Process::path((string) $app['checkout'])
            ->env($this->simulation->cleanEnvironment())
            ->timeout(60)
            ->run(['php', 'artisan', ...$command, '--no-interaction']);

        $said = trim($result->output());
        $start = strpos($said, '{');
        $decoded = $start === false ? null : json_decode(substr($said, $start), true);

        if (! $result->successful() || ! is_array($decoded)) {
            throw new RuntimeException("`{$command[0]}` in {$app['checkout']} failed: ".trim($result->errorOutput() ?: $said));
        }

        return $decoded;
    }
}
