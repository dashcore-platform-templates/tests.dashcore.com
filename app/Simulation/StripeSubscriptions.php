<?php

namespace App\Simulation;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * A simulated customer's subscriptions on Stripe's test account, through the
 * Stripe CLI — how a journey cancels one the way the customer would, with no
 * button in the portal for it: `stripe subscriptions cancel`, which Stripe
 * answers with customer.subscription.deleted, forwarded to the stand-in
 * (StripeForwarder) like any other test event.
 *
 * Test mode only, twice over: `--live` is never passed, and a subscription
 * Stripe reports as livemode is never touched. Found by the customer's
 * email — each run's prospect has an address no other run has used — and by
 * what was sold, so another journey's customer is never cancelled.
 */
class StripeSubscriptions
{
    public function __construct(private Simulation $simulation) {}

    /**
     * The one active test-mode subscription a customer holds whose
     * description starts with $sold ("API + CEO").
     *
     * @return array{id: string, description: string}
     *
     * @throws RuntimeException when the CLI cannot say, or there is not exactly one
     */
    public function active(string $email, string $sold): array
    {
        $found = [];

        foreach ($this->list(['customers', 'list', "--email={$email}", '--limit=10']) as $customer) {
            foreach ($this->list(['subscriptions', 'list', "--customer={$customer['id']}", '--status=active', '--limit=10']) as $subscription) {
                if (($subscription['livemode'] ?? true) === false && str_starts_with((string) ($subscription['description'] ?? ''), $sold)) {
                    $found[] = ['id' => (string) $subscription['id'], 'description' => (string) $subscription['description']];
                }
            }
        }

        if (count($found) !== 1) {
            throw new RuntimeException(sprintf('Stripe has %d active test %s subscription(s) for %s; the journey cancels exactly one.', count($found), $sold, $email));
        }

        return $found[0];
    }

    /**
     * The customer's test-mode subscription for $sold that Stripe has already
     * cancelled, if there is exactly one — what a re-run finds when an earlier
     * run cancelled it but stopped before its forwarder delivered the event.
     *
     * @return array{id: string, description: string}|null
     */
    public function cancelled(string $email, string $sold): ?array
    {
        $found = [];

        foreach ($this->list(['customers', 'list', "--email={$email}", '--limit=10']) as $customer) {
            foreach ($this->list(['subscriptions', 'list', "--customer={$customer['id']}", '--status=canceled', '--limit=10']) as $subscription) {
                if (($subscription['livemode'] ?? true) === false && str_starts_with((string) ($subscription['description'] ?? ''), $sold)) {
                    $found[] = ['id' => (string) $subscription['id'], 'description' => (string) $subscription['description']];
                }
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * Ask Stripe to send a cancelled subscription's customer.subscription.deleted
     * event again, to the forwarder this journey runs.
     *
     * @throws RuntimeException when there is no such event to resend
     */
    public function resendCancellation(string $id): void
    {
        foreach ($this->list(['events', 'list', '--type=customer.subscription.deleted', '--limit=50']) as $event) {
            if (($event['data']['object']['id'] ?? null) === $id && ($event['livemode'] ?? true) === false) {
                $this->run(['events', 'resend', (string) $event['id']]);

                return;
            }
        }

        throw new RuntimeException("Stripe has no customer.subscription.deleted event for {$id} to send again.");
    }

    /**
     * Cancel it now, as the customer would — not at the end of its period —
     * so Stripe sends customer.subscription.deleted while the journey runs.
     *
     * @throws RuntimeException when Stripe did not cancel it
     */
    public function cancel(string $id): void
    {
        // Already cancelled in test mode — a re-run after a journey that
        // stopped later on — is the state this step exists to reach.
        $now = self::json($this->run(['get', "/v1/subscriptions/{$id}"]));

        if (($now['status'] ?? null) === 'canceled' && ($now['livemode'] ?? true) === false) {
            return;
        }

        $result = $this->run(['subscriptions', 'cancel', $id, '--confirm']);
        $said = self::json($result);

        if (($said['status'] ?? null) !== 'canceled' || ($said['livemode'] ?? true) !== false) {
            throw new RuntimeException("Stripe did not cancel {$id}: ".mb_substr(trim($result), 0, 300));
        }
    }

    /**
     * The object the CLI printed. A command that acts on an account first
     * prints which one ("This command will be executed on the account with
     * the following details: > Mode: Test …"), so the JSON starts at the
     * first brace, not the first byte — the first live cancellation was a
     * success read as a failure.
     *
     * @return array<string, mixed>
     */
    private static function json(string $output): array
    {
        $start = strpos($output, '{');
        $said = $start === false ? null : json_decode(substr($output, $start), true);

        return is_array($said) ? $said : [];
    }

    /**
     * @param  list<string>  $arguments
     * @return list<array<string, mixed>>
     */
    private function list(array $arguments): array
    {
        $said = self::json($this->run($arguments));

        if (! is_array($said['data'] ?? null)) {
            throw new RuntimeException('`stripe '.implode(' ', array_slice($arguments, 0, 2)).'` did not answer with a list.');
        }

        return array_values(array_filter($said['data'], 'is_array'));
    }

    /** @param  list<string>  $arguments */
    private function run(array $arguments): string
    {
        $result = Process::env($this->simulation->cleanEnvironment())
            ->timeout(60)
            ->run([(string) config('simulation.stripe.cli'), ...$arguments]);

        if (! $result->successful()) {
            throw new RuntimeException('`stripe '.implode(' ', array_slice($arguments, 0, 2)).'` failed: '.mb_substr(trim($result->errorOutput() ?: $result->output()), 0, 300));
        }

        return $result->output();
    }
}
