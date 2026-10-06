<?php

namespace App\Simulation;

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Carries Stripe's webhooks to a stand-in app for as long as a journey
 * pays, through the Stripe CLI (`stripe listen --forward-to`).
 *
 * A practice fleet is served by Herd on *.test, which Stripe cannot reach,
 * and a payment no webhook reports is a payment the app never hears of: the
 * deal stays unpaid and the portal stays empty. The CLI listens on Stripe's
 * side and posts each event to the app, signed with its own secret — so the
 * app must hold that secret, which the journey checks by fingerprint first.
 *
 * Test mode only: `--live` is never passed, and the CLI's own default is
 * test events. Nothing it prints is repeated, since its first line carries
 * the signing secret.
 */
class StripeForwarder
{
    public function __construct(private Simulation $simulation) {}

    /**
     * The CLI's webhook signing secret, or null when it is not installed or
     * not signed in (`stripe login`). Asking for it is also how being signed
     * in is checked: the CLI has to reach Stripe to answer.
     */
    public function signingSecret(): ?string
    {
        try {
            $result = Process::env($this->simulation->cleanEnvironment())
                ->timeout(60)
                ->run([$this->cli(), 'listen', '--print-secret']);
        } catch (Throwable) {
            return null;
        }

        $secret = trim($result->output());

        return $result->successful() && str_starts_with($secret, 'whsec_') ? $secret : null;
    }

    /**
     * Start forwarding test events to $url, and return once the CLI says it
     * is ready — an event fired before then would be lost.
     */
    public function start(string $url): InvokedProcess
    {
        $said = '';

        $process = Process::env($this->simulation->cleanEnvironment())
            ->forever()
            ->start([
                $this->cli(), 'listen',
                '--forward-to', $url,
                '--events', implode(',', (array) config('simulation.stripe.events')),
            ], function (string $type, string $output) use (&$said) {
                $said .= $output;
            });

        $deadline = microtime(true) + (int) config('simulation.stripe.ready_timeout');

        while (! str_contains($said, 'Ready!')) {
            if (! $process->running()) {
                if (str_contains($said, 'Ready!')) {
                    break;
                }

                throw new RuntimeException('stripe listen stopped before it was ready: '.$this->withoutSecrets($said));
            }

            if (microtime(true) > $deadline) {
                $this->stop($process);

                throw new RuntimeException('stripe listen was not ready after '.config('simulation.stripe.ready_timeout').' seconds.');
            }

            usleep(100_000);
        }

        return $process;
    }

    public function stop(?InvokedProcess $process): void
    {
        if ($process === null) {
            return;
        }

        try {
            if ($process->running()) {
                $process->stop(5);
            }
        } catch (Throwable) {
            // Already gone.
        }
    }

    private function cli(): string
    {
        return (string) config('simulation.stripe.cli');
    }

    /**
     * What the CLI said, minus any line that names a secret.
     */
    private function withoutSecrets(string $said): string
    {
        $lines = array_filter(
            explode("\n", trim($said)),
            fn (string $line) => ! preg_match('/whsec_|sk_(test|live)_|rk_(test|live)_/', $line),
        );

        return mb_substr(trim(implode(' ', $lines)), 0, 300) ?: 'it said nothing.';
    }
}
