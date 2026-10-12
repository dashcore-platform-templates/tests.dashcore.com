<?php

namespace App\Simulation\CustomerJourneys;

use App\Simulation\CustomerJourney;
use App\Simulation\Provisioning\Provisioner;
use App\Simulation\Script;
use App\Simulation\StripeSubscriptions;
use Closure;
use Faker\Generator;
use RuntimeException;

/**
 * The customer cancels, and the fleet they paid for is taken down — with no
 * step done by hand, because it is a practice fleet's sale.
 *
 * It starts from the newest client fleet dcos says is built (the
 * Provisioning journey's) and checks its cockpit answers. Then, between
 * walks (CustomerJourney::next), the customer cancels their API + CEO
 * subscription the way Stripe's test mode allows — `stripe subscriptions
 * cancel`, at once rather than at the period's end — and Stripe's
 * customer.subscription.deleted reaches dcos through the forwarder, which
 * records the install cancelled. fleets is asked to take in what was
 * cancelled (`fleet:offboard-intake`): a practice sale is taken down there
 * and then and reported offboarded. The journey holds when dcos says
 * offboarded and the cockpit no longer answers.
 *
 * It takes a fleet down for good, so it only ever takes the newest one, and
 * only one a practice fleet sold and Herd serves here.
 */
class Cancellation extends CustomerJourney
{
    /** @var array<string, mixed>|null the request as dcos reports it */
    private ?array $built = null;

    /** @var array<string, mixed>|null the cockpit, as a fleet entry */
    private ?array $cockpit = null;

    private bool $cancelled = false;

    /** How long dcos is given to hear Stripe's cancellation, in seconds. */
    public int $hearWithin = 60;

    /** @var Closure(int): void how the journey waits between asking dcos again */
    public Closure $sleep;

    public function __construct(private Provisioner $provisioner, private StripeSubscriptions $stripe)
    {
        $this->sleep = fn (int $seconds) => sleep($seconds);
    }

    public function name(): string
    {
        return 'The customer cancels, and the fleet they paid for is taken down';
    }

    public function stripeWebhookApp(): ?string
    {
        return 'delivery';
    }

    public function stripeEvents(): array
    {
        return ['customer.subscription.deleted'];
    }

    public function guards(): array
    {
        return ['delivery'];
    }

    public function providesApps(): array
    {
        return ['cockpit'];
    }

    public function prepare(array $fleet, callable $say): array
    {
        $this->provisioner->source($fleet);
        $this->built = $this->provisioner->show($fleet['apps']['delivery']);
        $this->cockpit = $this->provisioner->cockpit($this->built, (string) $fleet['client_root']);
        $say("{$this->built['company']}'s fleet is {$this->built['install_slug']}, at {$this->cockpit['base_url']}; {$this->built['owner_email']} cancels it.");

        return ['cockpit' => $this->cockpit];
    }

    public function script(array $people, Generator $faker): Script
    {
        $company = $this->built['company'] ?? 'The customer';
        $url = $this->built['install_url'] ?? 'their cockpit';

        return (new Script)
            ->on('cockpit')
            ->visit('/login', "Before they cancel, {$company}'s cockpit answers at {$url}")
            ->see('Sign in', 'with its sign-in page')
            ->summary("{$company} cancelled: dcos recorded it from Stripe, fleets took their fleet down, dcos says it is offboarded, and {$url} no longer answers");
    }

    public function next(array $fleet, array $people, Generator $faker, callable $say): ?Script
    {
        if ($this->cancelled) {
            return null;
        }

        $this->cancelled = true;
        $uuid = (string) $this->built['uuid'];
        $delivery = $fleet['apps']['delivery'];

        $owner = (string) $this->built['owner_email'];
        $earlier = $this->stripe->cancelled($owner, 'API + CEO');

        if ($earlier !== null) {
            // An earlier run cancelled it and stopped before its forwarder
            // delivered the event: dcos is told again rather than the journey
            // giving up on a customer who has, in fact, cancelled.
            $say("{$earlier['description']} ({$earlier['id']}) is already cancelled on Stripe; it sends the cancellation to dcos again.");
            $this->stripe->resendCancellation($earlier['id']);
        } else {
            $subscription = $this->stripe->active($owner, 'API + CEO');
            $say("They cancel {$subscription['description']} ({$subscription['id']}) on Stripe.");
            $this->stripe->cancel($subscription['id']);
        }

        $heard = $this->waitFor($delivery, $uuid, ['cancelled', 'offboarding', 'offboarded']);
        $say("dcos has it {$heard['status']}.");

        $this->provisioner->offboard((string) $fleet['fleets_checkout'], (string) $fleet['install'], (int) $fleet['provisioning_timeout'], $say);

        $after = $this->provisioner->show($delivery, $uuid);

        if (($after['status'] ?? null) !== 'offboarded') {
            throw new RuntimeException("fleets did not take {$this->built['company']}'s fleet down: dcos has it ".($after['status'] ?? 'unknown').(empty($after['message']) ? '.' : " — {$after['message']}"));
        }

        $say('dcos says it is offboarded'.(empty($after['offboarded_at']) ? '' : " ({$after['offboarded_at']})").'.');

        if ($this->provisioner->answers((string) $this->cockpit['base_url'])) {
            throw new RuntimeException("dcos says {$this->built['company']}'s fleet is offboarded, but {$this->cockpit['base_url']} still answers.");
        }

        $say("{$this->cockpit['base_url']} no longer answers.");

        return null;
    }

    /**
     * Ask dcos until the request has reached one of $statuses: Stripe's
     * event reaches it through the forwarder, a moment after the cancel.
     *
     * @param  array<string, mixed>  $delivery
     * @param  list<string>  $statuses
     * @return array<string, mixed>
     */
    private function waitFor(array $delivery, string $uuid, array $statuses): array
    {
        // Laravel's clock, not time(): a test that stands in for the pause
        // moves this clock, so the wait ends when its window has passed
        // rather than spinning for real seconds.
        $deadline = now()->getTimestamp() + $this->hearWithin;

        do {
            $request = $this->provisioner->show($delivery, $uuid);

            if (in_array($request['status'] ?? null, $statuses, true)) {
                return $request;
            }

            ($this->sleep)(2);
        } while (now()->getTimestamp() < $deadline);

        throw new RuntimeException("dcos never heard the subscription end: after {$this->hearWithin} s it still has {$this->built['company']}'s install ".($request['status'] ?? 'unknown').'. Is its webhook getting customer.subscription.deleted?');
    }
}
