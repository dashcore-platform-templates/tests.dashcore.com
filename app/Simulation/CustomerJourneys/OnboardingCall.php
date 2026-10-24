<?php

namespace App\Simulation\CustomerJourneys;

use App\Simulation\CustomerJourney;
use App\Simulation\Onboarding\OnboardingStandIn;
use App\Simulation\Provisioning\Provisioner;
use App\Simulation\Script;
use App\Simulation\StandIns\AnthropicStandIn;
use Faker\Generator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Journey 6: the onboarding call. Dashcore's host schedules the new client's
 * session by picking their fleet, holds the call with Gabe, confirms what he
 * drafted from it and marks it held — and the answers reach the fleet's
 * Brief and its apps.
 *
 * The call happens after the build (REQ-109), on Dashcore's onboarding,
 * which here is the practice home fleet's own — a stand-in fleets cannot
 * reach. So fleets' report of the new fleet is handed to it, the call is
 * held from a script with no LiveKit or worker (each line filed as the
 * worker files it), and onboarding's own model call drafts the answers
 * against the Anthropic stand-in. Then the session is read as fleets reads
 * it, imported into the Brief and delivered, as staff do on the Brief tab
 * (OnboardingStandIn). The host's part — scheduling, confirming, holding —
 * is walked in the browser.
 *
 * It follows the build: the newest fleet dcos was told is built, whose
 * owner the call is with.
 */
class OnboardingCall extends CustomerJourney
{
    /** @var array<string, mixed>|null the request as dcos reports it */
    private ?array $built = null;

    /** @var array<string, string> what the owner says on the call, by question */
    private array $answers = [];

    private ?int $session = null;

    private int $walk = 0;

    public function __construct(private Provisioner $provisioner, private OnboardingStandIn $onboarding) {}

    public function name(): string
    {
        return "Dashcore holds the onboarding call with Gabe, and what the owner said reaches their fleet's Brief";
    }

    public function signsIn(): array
    {
        return ['onboarding' => 'Host'];
    }

    public function guards(): array
    {
        return ['delivery'];
    }

    public function prepare(array $fleet, callable $say): array
    {
        $this->provisioner->source($fleet);
        $this->built = $this->provisioner->show($fleet['apps']['delivery']);
        $this->provisioner->cockpit($this->built, (string) $fleet['client_root']);
        $install = (string) $this->built['install_slug'];

        $this->onboarding->report((string) $fleet['fleets_checkout'], (string) $fleet['apps']['onboarding']['checkout'], $install);
        $this->answers = $this->answersFor($this->built);
        $say("fleets reported {$this->built['company']} ({$install}) to onboarding, as it does every five minutes; onboarding lists it for the host to pick.");

        return [];
    }

    public function script(array $people, Generator $faker): Script
    {
        $host = $people['onboarding'];
        $who = $host->firstName();
        $company = (string) ($this->built['company'] ?? 'The client');

        return $this->signIn((new Script)->on('onboarding'), $host)
            ->visit('/onboardings', "{$who} opens the onboarding sessions")
            ->click('button[wire\:click="create"]', "{$who} schedules an onboarding")
            ->select('#fleet', (string) ($this->built['install_slug'] ?? ''), "{$who} picks {$company}'s fleet from what fleets reported")
            ->see('Its apps', "The fleet's apps decide what the call asks")
            ->choose('#hostId', $host->name, "{$who} hosts it")
            ->click('form[wire\:submit="save"] button[type="submit"]', "{$who} schedules it")
            ->see('Start the call with', "{$company}'s run sheet opens, ready for the call")
            ->summary("{$who} scheduled {$company}'s onboarding by picking their fleet");
    }

    public function next(array $fleet, array $people, Generator $faker, callable $say): ?Script
    {
        $this->walk++;
        $checkout = (string) $fleet['apps']['onboarding']['checkout'];
        $install = (string) $this->built['install_slug'];
        $host = $people['onboarding'];
        $company = (string) $this->built['company'];

        if ($this->walk === 1) {
            $this->session = (int) $this->onboarding->session($checkout, $install)['id'];
            $model = app(AnthropicStandIn::class, ['replies' => [['when' => '"messages"', 'text' => (string) json_encode($this->drafted())]]])->start();

            try {
                $call = $this->onboarding->holdCall($checkout, $this->session, $host->email, $this->transcript(), $model);
            } finally {
                $model->stop();
            }

            $say("The call with Gabe: {$call['lines']} lines filed as his worker files them, then ended; onboarding drafted {$call['proposed']} answers from the transcript against the Anthropic stand-in.");

            $script = $this->signIn((new Script)->on('onboarding'), $host)
                ->visit("/onboardings/{$this->session}", "{$host->firstName()} opens {$company}'s run sheet after the call")
                ->see(count($this->answers).' to review', "Gabe's drafted answers wait for the host");

            foreach (array_keys($this->answers) as $question) {
                $script->click("button[wire\\:click=\"confirmProposal('{$question}')\"]", "{$host->firstName()} confirms {$question}");
            }

            return $script
                ->see('Every question answered', 'Every question is answered')
                ->click('button[wire\:click="markHeld"]', "{$host->firstName()} marks the session held")
                ->see('Reopen', 'The session is held')
                ->summary("{$host->firstName()} confirmed what Gabe drafted from the call and marked {$company}'s session held");
        }

        if ($this->walk === 2) {
            $session = $this->onboarding->session($checkout, $install);

            if (($session['status'] ?? null) !== 'held') {
                throw new RuntimeException("{$company}'s session is ".($session['status'] ?? 'unknown').', not held.');
            }

            $done = $this->onboarding->importAndDeliver((string) $fleet['fleets_checkout'], $install, $session);
            $say(trim((string) strtok($done['imported'], "\n")));
            $say("fleets delivered the Brief to {$company}'s apps.");
        }

        return null;
    }

    /**
     * What the owner tells Gabe: every question a ceo + api + login fleet is
     * asked, so the session can be held complete.
     *
     * @param  array<string, mixed>  $built
     * @return array<string, string>
     */
    private function answersFor(array $built): array
    {
        $company = (string) $built['company'];
        $owner = (string) $built['owner_name'];

        return [
            'company.contact' => "{$owner}, the owner",
            'company.owner_name' => $owner,
            'company.owner_email' => (string) $built['owner_email'],
            'company.hopes' => 'To know where the business stands every morning without chasing anyone.',
            'company.name' => $company,
            'company.legal_name' => "{$company} LLC",
            'company.website' => 'https://'.Str::slug($company).'.example.com',
            'company.phone' => '+1 503 555 0142',
            'company.address' => '1200 NW Front Ave, Portland, OR 97209',
            'company.industry' => 'Custom furniture made to order for homes and offices.',
            'company.timezone' => 'America/Los_Angeles',
            'company.dns_managed_by' => 'Dashcore',
            'company.dns_provider' => 'Cloudflare',
            'api.profile' => "{$company} builds custom tables and shelving for homes and small offices around Portland.",
            'ceo.goals' => "Ship 40 tables this year\nHire a second carpenter",
            'ceo.one_number' => 'Tables shipped each month',
            'ceo.plan' => 'No written plan yet: the goals are the plan for now.',
            'login.access' => "Just {$owner} for now, with every app.",
        ];
    }

    /**
     * The call, as Gabe's worker reports it: Gabe asks, the owner answers.
     *
     * @return list<array<string, string>>
     */
    private function transcript(): array
    {
        $first = Str::before((string) $this->built['owner_name'], ' ');
        $lines = [['role' => 'assistant', 'content' => "Hi {$first}, I'm Gabe. I'll ask a few things so your apps are ready for you."]];

        foreach ($this->answers as $question => $answer) {
            $lines[] = ['role' => 'assistant', 'content' => 'Next: '.str_replace(['company.', 'api.', 'ceo.', 'login.', '_'], ['', '', '', '', ' '], $question).'?'];
            $lines[] = ['role' => 'user', 'speaker' => 'client', 'name' => $first, 'content' => $this->said($answer)];
        }

        $lines[] = ['role' => 'assistant', 'content' => "That's everything. Thank you, {$first}."];

        return $lines;
    }

    /**
     * What the onboarding model drafts from that call: each answer, with the
     * owner's own words as its evidence.
     *
     * @return array{answers: list<array{question: string, answer: string, evidence: string}>, admins: list<mixed>, recap: string}
     */
    private function drafted(): array
    {
        return [
            'answers' => array_map(fn (string $question, string $answer): array => [
                'question' => $question,
                'answer' => $answer,
                'evidence' => $this->said($answer),
            ], array_keys($this->answers), $this->answers),
            'admins' => [],
            'recap' => 'Covered the company, its goals and who uses the apps.',
        ];
    }

    private function said(string $answer): string
    {
        return 'It is '.str_replace("\n", ', and ', $answer).'.';
    }
}
