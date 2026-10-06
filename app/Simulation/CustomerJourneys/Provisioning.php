<?php

namespace App\Simulation\CustomerJourneys;

use App\Simulation\CustomerJourney;
use App\Simulation\Provisioning\Provisioner;
use App\Simulation\Script;
use Faker\Generator;
use RuntimeException;

/**
 * Journey 3: the order a customer paid for builds their own fleet, with no
 * step done by hand.
 *
 * When an API + CEO subscription is paid, the practice fleet's dcos records
 * "paid, needs an install" (its Stripe webhook). fleets.dashcore.com — a
 * local tool nothing can call — asks for it with `fleet:intake`, builds the
 * customer a fleet of their own under Herd (their hub, their cockpit, login
 * and standards, at <company>.practice), invites the owner into the cockpit
 * and reports it built, with its address, back to dcos. This journey runs
 * that intake itself rather than waiting for the fleet host's schedule, then
 * reads back from dcos where the fleet was built and opens it.
 *
 * It starts where the Subscription journey ends: the API + CEO plan sold
 * through sales and subscribed to, which leaves a paid request waiting in
 * dcos. It does not walk that again — a fleet takes minutes to build, and
 * the sale is its own journey — so with nothing waiting it says to walk
 * `subscription` first.
 */
class Provisioning extends CustomerJourney
{
    /** @var array<string, mixed>|null the request as dcos reports it, once built */
    private ?array $built = null;

    public function __construct(private Provisioner $provisioner) {}

    public function name(): string
    {
        return "The paid order builds the customer's own fleet, and its cockpit answers";
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
        $source = $this->provisioner->source($fleet);
        $delivery = $fleet['apps']['delivery'];
        $pending = $this->provisioner->pending($delivery);

        if ($pending === []) {
            throw new RuntimeException("No paid API + CEO fleet is waiting in {$fleet['name']}'s dcos. Walk `tests:journey subscription` first — it sells the plan through sales and subscribes to it — then run this again.");
        }

        $request = end($pending);
        $say("{$request['company']} paid for ".implode(' + ', array_map('strtoupper', $request['apps'])).", for {$request['owner_email']}: fleets builds it now.");

        $built = $this->provisioner->intake((string) $fleet['fleets_checkout'], $source, (int) $fleet['provisioning_timeout'], $say);
        $this->built = $this->provisioner->show($delivery, (string) $request['uuid']);

        if (! $built && ($this->built['status'] ?? null) !== 'built') {
            throw new RuntimeException("fleets did not build {$request['company']}'s fleet: dcos has it ".($this->built['status'] ?? 'unknown').(empty($this->built['message']) ? '.' : " — {$this->built['message']}"));
        }

        return ['cockpit' => $this->provisioner->cockpit($this->built, (string) $fleet['client_root'])];
    }

    public function script(array $people, Generator $faker): Script
    {
        $company = $this->built['company'] ?? 'The customer';
        $url = $this->built['install_url'] ?? 'their cockpit';

        return (new Script)
            ->on('cockpit')
            ->visit('/login', "{$company}'s cockpit is opened at {$url}")
            ->see('Sign in', 'It answers with its sign-in page')
            ->summary("{$company} paid, and fleets built them a fleet of their own: their cockpit answers at {$url}");
    }
}
