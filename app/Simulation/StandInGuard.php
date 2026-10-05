<?php

namespace App\Simulation;

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
     * Why a checkout may not be driven, or null when it may.
     */
    public function checkoutRefusal(?string $checkout): ?string
    {
        if (! is_string($checkout) || ! is_dir($checkout)) {
            return 'there is no checkout at '.var_export($checkout, true).'.';
        }

        if (! self::isStandIn($checkout)) {
            return "{$checkout} is not a stand-in app; only *.fakecompany.com and *.practice checkouts are driven.";
        }

        return null;
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
     * Config values as the app itself resolves them.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function read(string $checkout, array $keys): array
    {
        // Core only — no tinker, which an app may not have installed.
        $code = sprintf(
            'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $keys = %s; echo "\\n", %s, json_encode(array_combine($keys, array_map(fn ($key) => config($key), $keys))), "\\n";',
            var_export($keys, true),
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
