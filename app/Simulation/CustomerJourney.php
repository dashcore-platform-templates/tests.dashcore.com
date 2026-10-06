<?php

namespace App\Simulation;

use Faker\Generator;

/**
 * One stage of a customer's whole life with Dashcore — enquiring, paying,
 * being provisioned, signing in, being briefed — walked across every app it
 * touches, against a stand-in fleet (`simulation.fleets`).
 *
 * Unlike a Proving Ground journey it is not one person's work in one app.
 * The prospect is nobody until they fill in a form, and whoever picks the
 * work up next signs in to another app: a script switches app with on(),
 * and the values that cross between apps are the ones it remember()s off
 * the page, so what arrives is checked against what was actually typed.
 */
abstract class CustomerJourney
{
    /** For people: "A prospect enquires on the website and reaches sales". */
    abstract public function name(): string;

    /**
     * @param  array<string, Persona>  $people  who signs in, by the app they sign in to (signsIn())
     */
    abstract public function script(array $people, Generator $faker): Script;

    /**
     * The persona role each app's sign-in needs, by app. An app the journey
     * only visits signs nobody in.
     *
     * @return array<string, string>
     */
    public function signsIn(): array
    {
        return [];
    }

    /**
     * The app whose Stripe webhook must hear about this journey's payments,
     * or null when nothing in it pays. A journey that names one is walked
     * only while the Stripe CLI forwards test events to that app (its
     * `stripe_webhook` on the fleet entry), and only once the app has shown
     * it is on Stripe test keys.
     */
    public function stripeWebhookApp(): ?string
    {
        return null;
    }

    /**
     * Sign in to an app of the fleet as its persona, through its login form
     * (`login` on the app's fleet entry overrides the fields).
     *
     * @param  array<string, string>  $login
     */
    protected function signIn(Script $script, Persona $persona, array $login = []): Script
    {
        return Journey::signInSteps($script, $persona, $login);
    }
}
