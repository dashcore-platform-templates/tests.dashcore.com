<?php

namespace App\Simulation;

/**
 * The steps of one journey, in the plain words the feed shows, and what the
 * browser does for each. Built fresh for every run, so each run types its own
 * fake data. The browser side is simulator/run.mjs; the step kinds here are
 * the ones it knows.
 *
 * remember() keeps text from the page under a name, and any later step,
 * label or the summary can use it as {name}: that is how a journey picks up
 * an existing record and keeps working on it.
 *
 * A customer journey crosses apps: on() says which app of the fleet the
 * steps after it belong to, and each visit opens its path on that app. What
 * was remembered on one app is still there on the next, so the email a
 * prospect typed into the website is the one a rep searches for in sales.
 */
final class Script
{
    /** @var list<array{label: string, do: string}&array<string, mixed>> */
    private array $steps = [];

    private ?string $summary = null;

    /** The app the next steps run against, in a journey across apps. */
    private ?string $app = null;

    /**
     * Run the steps after this one against another app of the fleet.
     */
    public function on(string $app): self
    {
        $this->app = $app;

        return $this;
    }

    /**
     * The apps the steps run against, in the order the journey reaches them.
     *
     * @return list<string>
     */
    public function apps(): array
    {
        return array_values(array_unique(array_filter(array_column($this->steps, 'on'))));
    }

    public function visit(string $path, string $label): self
    {
        return $this->add('visit', $label, ['path' => $path]);
    }

    public function fill(string $target, string $value, string $label): self
    {
        return $this->add('fill', $label, ['target' => $target, 'value' => $value]);
    }

    public function select(string $target, string $value, string $label): self
    {
        return $this->add('select', $label, ['target' => $target, 'value' => $value]);
    }

    /**
     * Pick an option by the text a person sees, e.g. an owner's name.
     */
    public function choose(string $target, string $option, string $label): self
    {
        return $this->add('select', $label, ['target' => $target, 'option' => $option]);
    }

    /**
     * Keep an element's text (or a field's value) as {$as} for later steps.
     */
    public function remember(string $target, string $as, string $label): self
    {
        return $this->add('remember', $label, ['target' => $target, 'as' => $as]);
    }

    /**
     * @param  bool  $confirm  accept the browser confirm() the click opens
     */
    public function click(string $target, string $label, bool $confirm = false): self
    {
        return $this->add('click', $label, ['target' => $target, 'confirm' => $confirm]);
    }

    public function see(string $text, string $label): self
    {
        return $this->add('see', $label, ['text' => $text]);
    }

    public function gone(string $target, string $label): self
    {
        return $this->add('gone', $label, ['target' => $target]);
    }

    /**
     * Wait until a field holds a value something else put there — a
     * challenge widget's token, say — rather than one the journey typed.
     */
    public function filled(string $target, string $label): self
    {
        return $this->add('filled', $label, ['target' => $target]);
    }

    /**
     * Wait for text that something outside the browser puts on the page —
     * a webhook landing after the page loaded — reloading until it shows
     * or $seconds have gone.
     */
    public function seeWithin(string $text, int $seconds, string $label): self
    {
        return $this->add('see', $label, ['text' => $text, 'reload_for_ms' => $seconds * 1000]);
    }

    /**
     * Open the newest email the current app sent to $to — from the mail log
     * in its checkout, where a stand-in's mail goes — and keep the first
     * text in it matching $pattern (a JavaScript regular expression) as {$as}.
     * Waits up to $seconds for it, since mail may go out from a queue.
     */
    public function mail(string $to, string $pattern, string $as, string $label, int $seconds = 30): self
    {
        return $this->add('mail', $label, ['to' => $to, 'pattern' => $pattern, 'as' => $as, 'within_ms' => $seconds * 1000]);
    }

    /**
     * Pay on a hosted Stripe Checkout page the browser has been sent to,
     * with a test card, and wait to be sent back. $card holds number,
     * expiry (MM / YY), cvc, name, email and postal_code; the email and the
     * billing fields are filled only when the page asks for them.
     *
     * @param  array{number: string, expiry: string, cvc: string, name: string, email: string, postal_code: string}  $card
     */
    public function payByCard(array $card, string $label, int $seconds = 90, string $host = 'checkout.stripe.com'): self
    {
        return $this->add('stripe_checkout', $label, [...$card, 'host' => $host, 'within_ms' => $seconds * 1000]);
    }

    /**
     * One line for the feed once the run passes, e.g. "Maya added lead Dana Fox".
     */
    public function summary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    /**
     * @return list<array{label: string, do: string}&array<string, mixed>>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /**
     * @param  array<string, mixed>  $with
     */
    private function add(string $do, string $label, array $with): self
    {
        $this->steps[] = ['do' => $do, 'label' => $label, ...$with, ...($this->app === null ? [] : ['on' => $this->app])];

        return $this;
    }
}
