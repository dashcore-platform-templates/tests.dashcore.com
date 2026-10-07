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
     * Cancel it now, as the customer would — not at the end of its period —
     * so Stripe sends customer.subscription.deleted while the journey runs.
     *
     * @throws RuntimeException when Stripe did not cancel it
     */
    public function cancel(string $id): void
    {
        $result = $this->run(['subscriptions', 'cancel', $id, '--confirm']);
        $said = json_decode($result, true);

        if (! is_array($said) || ($said['status'] ?? null) !== 'canceled' || ($said['livemode'] ?? true) !== false) {
            throw new RuntimeException("Stripe did not cancel {$id}: ".mb_substr(trim($result), 0, 300));
        }
    }

    /**
     * @param  list<string>  $arguments
     * @return list<array<string, mixed>>
     */
    private function list(array $arguments): array
    {
        $said = json_decode($this->run($arguments), true);

        if (! is_array($said) || ! is_array($said['data'] ?? null)) {
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
