<?php

namespace App\Simulation\CustomerJourneys;

use App\Simulation\CustomerJourney;
use App\Simulation\Provisioning\OwnerSignIn;
use App\Simulation\Provisioning\Provisioner;
use App\Simulation\Script;
use App\Simulation\Simulation;
use App\Simulation\StandIns\AnthropicStandIn;
use Faker\Generator;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Journey 5: the new owner opens their cockpit and today's briefing is
 * already there, written for them.
 *
 * The build's last step asks ceo for today's briefing (`assistant:brief`,
 * REQ-117), so the owner never meets "No briefing yet". A practice fleet has
 * no Anthropic key, so there the build only warns. This runs that same step
 * against the Anthropic stand-in — a canned briefing, the same every run,
 * drafted and then checked as ceo always does — and the owner signs in again
 * the way they did on their first sign-in (OwnerSignIn) and finds it on
 * their dashboard. Where the build did write one, the step reads it back
 * and nothing is asked of the stand-in.
 *
 * It follows Journey 4 (First sign-in), whose password it signs in with.
 */
class FirstBriefing extends CustomerJourney
{
    /** @var array<string, mixed>|null the request as dcos reports it */
    private ?array $built = null;

    /** @var array{door: string, email: string, password: string}|null */
    private ?array $signIn = null;

    private string $headline = '';

    public function __construct(
        private Provisioner $provisioner,
        private OwnerSignIn $signIns,
        private Simulation $simulation,
    ) {}

    public function name(): string
    {
        return "The owner opens their cockpit and finds today's briefing written for them";
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
        $cockpit = $this->provisioner->cockpit($this->built, (string) $fleet['client_root']);
        $this->signIn = $this->signIns->of((string) $this->built['install_slug'])
            ?? throw new RuntimeException("Nobody has signed in to {$this->built['company']}'s cockpit yet. Walk `tests:journey first-sign-in` first: the owner chooses their password there.");

        $standIn = app(AnthropicStandIn::class, ['replies' => $this->replies()])->start();

        try {
            $result = Process::path($cockpit['checkout'])
                ->env([...$this->simulation->cleanEnvironment(), ...$standIn->env()])
                ->timeout(300)
                ->run(['php', 'artisan', 'assistant:brief', '--no-interaction']);
            $asked = count($standIn->requests());
        } finally {
            $standIn->stop();
        }

        if (! $result->successful()) {
            throw new RuntimeException("ceo wrote no briefing for {$this->built['company']}: ".Str::limit(trim($result->errorOutput() ?: $result->output()), 500));
        }

        $this->headline = trim((string) strtok(trim($result->output()), "\n"));
        $say($asked > 0
            ? "The build wrote no first briefing (a practice fleet has no Anthropic key), so ceo's own step, `assistant:brief`, wrote it against the Anthropic stand-in: drafted, then checked ({$asked} model calls)."
            : 'The build had already written today\'s briefing; `assistant:brief` read it back.');
        $say("Its headline: {$this->headline}");

        return ['cockpit' => $cockpit];
    }

    public function script(array $people, Generator $faker): Script
    {
        $email = $this->signIn['email'] ?? 'owner@example.com';
        $first = Str::before((string) ($this->built['owner_name'] ?? 'Owner'), ' ');
        $script = (new Script)->on('cockpit');

        // ── Back in, by the door their first sign-in used ──
        $script = ($this->signIn['door'] ?? null) === OwnerSignIn::LOGIN
            ? $script->visit('/auth/sso/redirect', "{$first} opens their cockpit, which sends them to login")
            : $script->visit('/login', "{$first} opens their cockpit's sign-in page");

        return $script
            ->fill('input[type="email"]', $email, "They enter {$email}")
            ->fill('input[type="password"]', (string) ($this->signIn['password'] ?? ''), 'and their password')
            ->click('form button[type="submit"]', 'They sign in')
            ->see(", {$first}", "Their dashboard greets {$first}")
            ->see($this->headline !== '' ? $this->headline : 'Welcome to your cockpit', "Today's briefing is there: \"{$this->headline}\"")
            ->summary("{$first} signed in and found today's briefing in their cockpit: \"{$this->headline}\"");
    }

    /**
     * The stand-in's answers: ceo's check of a draft first (its prompt says
     * so), then the draft itself for anything else ceo asks.
     *
     * @return list<array{when: string, text: string}>
     */
    private function replies(): array
    {
        $first = Str::before((string) ($this->built['owner_name'] ?? 'Owner'), ' ');
        $company = (string) ($this->built['company'] ?? 'your company');
        $briefing = [
            'headline' => "Welcome to your cockpit, {$first}",
            'sections' => [[
                'title' => 'Getting started',
                'items' => [
                    ['text' => "{$company}'s fleet is built: your cockpit, sign-in and API are running.", 'refs' => []],
                    ['text' => 'Your onboarding call with Dashcore comes next. What you tell Gabe there shapes these briefings.', 'refs' => []],
                ],
            ]],
        ];

        return [
            ['when' => 'Check it, sentence by sentence', 'text' => (string) json_encode([...$briefing, 'corrections' => []])],
            ['when' => '"messages"', 'text' => (string) json_encode($briefing)],
        ];
    }
}
