<?php

namespace App\Simulation;

use App\Services\FleetExport;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * What stands between a simulation and a real app.
 *
 * Two questions, asked before anything is driven or prepared. Is this
 * checkout a stand-in — a *.fakecompany.com copy, or an app of someone's
 * practice fleet (*.practice) — rather than a real install? And will it keep
 * its mail to itself? A simulated prospect types an address into a form, and
 * a real email from a simulation is the failure this exists to prevent, so
 * an app that cannot be shown to log its mail is refused, not trusted.
 *
 * An app that takes payments is asked a third: is it on its provider's test
 * keys? A simulated prospect pays with a test card, and on live keys that
 * would be a real charge. Secrets are never brought back to ask it: the app
 * answers whether its key starts as a test key does, or with a fingerprint
 * of a secret to compare, and nothing else crosses.
 */
class StandInGuard
{
    /** Marks the line carrying the answer, in whatever else the app prints. */
    private const MARKER = '@@simulation-config@@';

    /**
     * Every app's mail must go to its log. An app may ask for more: the
     * website sends straight to Resend's API, which no mailer setting stops,
     * and holds it only while it runs as `local`.
     */
    private const MAIL = ['mail.default' => 'log'];

    public function __construct(private Simulation $simulation) {}

    /**
     * Whether a checkout is one the simulation may drive: its folder names a
     * fake company or a practice fleet.
     */
    public static function isStandIn(?string $checkout): bool
    {
        if (! is_string($checkout) || trim($checkout) === '') {
            return false;
        }

        $name = basename(rtrim($checkout, '/'));

        return str_contains($name, 'fakecompany') || str_contains($name, '.practice');
    }

    /**
     * Why a checkout may not be driven, or null when it may. Unless it is a
     * client fleet this install's own journey has just built ($own false),
     * a stand-in fleets lists under any other fleet is refused too.
     */
    public function checkoutRefusal(?string $checkout, bool $own = true): ?string
    {
        if (! is_string($checkout) || ! is_dir($checkout)) {
            return 'there is no checkout at '.var_export($checkout, true).'.';
        }

        if (! self::isStandIn($checkout)) {
            return "{$checkout} is not a stand-in app; only *.fakecompany.com and *.practice checkouts are driven.";
        }

        return $own ? OwnFleet::refusal($checkout, app(FleetExport::class)) : null;
    }

    /**
     * Why an app's mail cannot be trusted to stay put, or null when it can.
     * The answer is read from the app's own configuration, in its own
     * checkout, under an empty environment — what its .env and config files
     * say, never a value this app's shell happens to carry. A read that fails
     * is a refusal: unknown is not safe.
     *
     * @param  array<string, mixed>  $also  further config values the app must hold
     */
    public function mailRefusal(string $checkout, array $also = []): ?string
    {
        $required = [...self::MAIL, ...$also];

        try {
            $actual = $this->read($checkout, array_keys($required));
        } catch (Throwable $e) {
            return "could not read the mail settings of {$checkout}: {$e->getMessage()}";
        }

        foreach ($required as $key => $value) {
            if (! array_key_exists($key, $actual) || $actual[$key] !== $value) {
                return sprintf(
                    '%s would send real email: %s is %s, and must be %s.',
                    basename($checkout),
                    $key,
                    json_encode($actual[$key] ?? null),
                    json_encode($value),
                );
            }
        }

        return null;
    }

    /**
     * Why an app may be on live payment keys, or null when every key it was
     * asked about is a test key. `$prefixes` maps a config key to the start a
     * test key has, e.g. services.stripe.secret => sk_test_. The key itself
     * never leaves the app — only whether it starts that way.
     *
     * @param  array<string, string>  $prefixes
     */
    public function testModeRefusal(string $checkout, array $prefixes): ?string
    {
        $probes = [];

        foreach ($prefixes as $key => $prefix) {
            $probes["starts_with:{$key}"] = [$key, 'starts_with', (string) $prefix];
        }

        try {
            $answers = $this->probe($checkout, $probes);
        } catch (Throwable $e) {
            return "could not read the payment settings of {$checkout}: {$e->getMessage()}";
        }

        foreach ($prefixes as $key => $prefix) {
            if (($answers["starts_with:{$key}"] ?? null) !== true) {
                return sprintf('%s is not in test mode: %s is not a %s key.', basename($checkout), $key, $prefix);
            }
        }

        return null;
    }

    /**
     * Why an app is not set up for a journey, or null when it holds every
     * value the journey requires (CustomerJourney::requires()). Values only —
     * never ask this about a secret; testModeRefusal() and holdsSecret() are
     * how those are asked about without leaving the app.
     *
     * @param  array<string, mixed>  $required  config key => the value it must hold
     */
    public function settingRefusal(string $checkout, array $required): ?string
    {
        if ($required === []) {
            return null;
        }

        try {
            $actual = $this->read($checkout, array_keys($required));
        } catch (Throwable $e) {
            return "could not read the settings of {$checkout}: {$e->getMessage()}";
        }

        foreach ($required as $key => $value) {
            if (! array_key_exists($key, $actual) || $actual[$key] !== $value) {
                return sprintf(
                    '%s is not set up for this journey: %s is %s, and must be %s.',
                    basename($checkout),
                    $key,
                    json_encode($actual[$key] ?? null),
                    json_encode($value),
                );
            }
        }

        return null;
    }

    /**
     * Whether an app's config value starts with a prefix — that it holds a
     * key of a given kind (`github_pat_`, say) — answered yes or no inside
     * the app, so the value never leaves it. Null when it could not be asked.
     */
    public function startsWith(string $checkout, string $key, string $prefix): ?bool
    {
        try {
            $answers = $this->probe($checkout, ["starts_with:{$key}" => [$key, 'starts_with', $prefix]]);
        } catch (Throwable) {
            return null;
        }

        return is_bool($answers["starts_with:{$key}"] ?? null) ? $answers["starts_with:{$key}"] : null;
    }

    /**
     * Whether an app holds the same secret as this process does — the Stripe
     * CLI's webhook signing secret, say — compared by fingerprint so neither
     * value is printed or passed to the other side. Null when it could not
     * be read.
     */
    public function holdsSecret(string $checkout, string $key, string $secret): ?bool
    {
        try {
            $answers = $this->probe($checkout, ["sha256:{$key}" => [$key, 'sha256', '']]);
        } catch (Throwable) {
            return null;
        }

        $fingerprint = $answers["sha256:{$key}"] ?? null;

        return is_string($fingerprint) && $secret !== '' && hash_equals($fingerprint, hash('sha256', $secret));
    }

    /**
     * Config values as the app itself resolves them.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function read(string $checkout, array $keys): array
    {
        return $this->probe($checkout, array_combine($keys, array_map(fn (string $key) => [$key, 'value', ''], $keys)));
    }

    /**
     * Ask the app about its own config, in its own checkout, under an empty
     * environment. Each probe is [config key, kind, argument]: `value` brings
     * the value back, `starts_with` only whether it starts with the argument,
     * `sha256` only its fingerprint — the last two are how a secret is asked
     * about without it ever leaving the app.
     *
     * @param  array<string, array{0: string, 1: string, 2: string}>  $probes  by the name each answer comes back under
     * @return array<string, mixed>
     */
    private function probe(string $checkout, array $probes): array
    {
        // Core only — no tinker, which an app may not have installed.
        $code = sprintf(
            'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $probes = %s; $answers = []; foreach ($probes as $name => [$key, $kind, $argument]) { $value = config($key); $answers[$name] = match ($kind) { "starts_with" => is_string($value) && $argument !== "" && str_starts_with($value, $argument), "sha256" => is_string($value) && $value !== "" ? hash("sha256", $value) : null, default => $value }; } echo "\\n", %s, json_encode($answers), "\\n";',
            var_export($probes, true),
            var_export(self::MARKER, true),
        );

        $result = Process::path($checkout)
            ->env($this->simulation->cleanEnvironment())
            ->timeout(60)
            ->run(['php', '-r', $code]);

        if (! $result->successful()) {
            throw new RuntimeException('it exited with '.$result->exitCode().'.');
        }

        foreach (explode("\n", $result->output()) as $line) {
            if (str_starts_with($line, self::MARKER)) {
                $values = json_decode(substr($line, strlen(self::MARKER)), true);

                if (is_array($values)) {
                    return $values;
                }
            }
        }

        throw new RuntimeException('it did not say.');
    }
}
