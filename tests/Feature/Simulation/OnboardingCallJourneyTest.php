<?php

namespace Tests\Feature\Simulation;

use App\Simulation\StandIns\AnthropicStandIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Journey 6: the onboarding call. The new fleet reaches Dashcore's stand-in
 * onboarding as fleets reports it, the host schedules the session by picking
 * it, the call is held from a script and drafted against the Anthropic
 * stand-in, the host confirms and holds it, and fleets imports it into the
 * Brief and delivers it.
 */
class OnboardingCallJourneyTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private bool $held = false;

    private bool $holdLands = true;

    /** @var list<array<string, mixed>> each script the browser walked, in order */
    private array $walked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/pg-onboarding-call-'.bin2hex(random_bytes(4));

        foreach (['dcos.travischapman.practice', 'onboarding.travischapman.practice', 'ceo.acme-care.practice', 'fleets.dashcore.com'] as $checkout) {
            File::ensureDirectoryExists("{$this->root}/{$checkout}");
            File::put("{$this->root}/{$checkout}/artisan", '');
        }

        config([
            'simulation.screenshots' => "{$this->root}/shots",
            'simulation.fleets.practice.install' => 'practice-travischapman',
            'simulation.fleets.practice.apps.delivery.checkout' => "{$this->root}/dcos.travischapman.practice",
            'simulation.fleets.practice.apps.onboarding.checkout' => "{$this->root}/onboarding.travischapman.practice",
            'simulation.fleets.practice.apps.onboarding.base_url' => 'https://onboarding.travischapman.practice.test',
            'simulation.fleets.practice.fleets_checkout' => "{$this->root}/fleets.dashcore.com",
            'simulation.fleets.practice.client_root' => $this->root,
        ]);

        $this->app->bind(AnthropicStandIn::class, fn ($app, array $parameters) => new class($parameters['replies']) extends AnthropicStandIn
        {
            public function start(): self
            {
                return $this;
            }

            public function url(): string
            {
                return 'http://127.0.0.1:9';
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function fakeProcesses(): void
    {
        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;

            if (($command[1] ?? null) === '-r') {
                $answers = str_contains($command[2], "'starts_with'")
                    ? json_encode(['starts_with:services.stripe.secret' => true])
                    : json_encode(['mail.default' => 'log']);

                return Process::result("\n@@simulation-config@@{$answers}\n");
            }

            return match ($command[2] ?? null) {
                'installs:show' => Process::result(json_encode([
                    'uuid' => 'u-1', 'status' => 'built', 'company' => 'Acme Care', 'owner_name' => 'Jane Doe', 'owner_email' => 'jane@acmecare.test',
                    'install_slug' => 'acme-care', 'install_url' => 'http://ceo.acme-care.practice.test', 'message' => 'Built.',
                ])."\n"),
                'onboarding:stand-in-host' => Process::result("  INFO  journey.host@example.com hosts sessions here.\n"),
                'fleet:report-onboarding' => Process::result(json_encode(['fleets' => [['slug' => 'acme-care', 'name' => 'Acme Care', 'practice' => true, 'apps' => ['api', 'ceo', 'login']]]])."\n"),
                'onboarding:receive-fleets' => Process::result("  INFO  1 client fleet(s) known.\n"),
                'onboarding:fleet-session' => Process::result(($this->held ? '{"session": {"id": 7, "status": "held", "brief": {}}}' : '{"session": {"id": 7, "status": "scheduled"}}')."\n"),
                'avatar:scripted-call' => Process::result(json_encode(['call' => 3, 'lines' => 38, 'answers_drafted' => true, 'proposed' => 18])."\n"),
                'fleet:onboarding-import' => Process::result("  INFO  Imported onboarding session #7 into Acme Care's Brief.\n"),
                'fleet:deliver-brief' => Process::result("  ceo .......... delivered\n"),
                // The browser: every step passes; holding the session is what
                // the second walk does.
                default => Process::result($this->everyStepPasses((string) $process->input)),
            };
        });
    }

    private function everyStepPasses(string $input): string
    {
        $this->walked[] = json_decode($input, true);
        $steps = (array) (json_decode($input, true)['steps'] ?? []);
        $this->held = $this->held || ($this->holdLands && collect($steps)->contains(fn (array $step) => ($step['target'] ?? null) === 'button[wire\:click="markHeld"]'));
        $events = [['event' => 'started']];

        foreach (array_keys($steps) as $i) {
            $events[] = ['event' => 'step', 'position' => $i, 'status' => 'passed', 'ms' => 50, 'url' => 'https://onboarding.travischapman.practice.test/', 'error' => null];
        }

        $events[] = ['event' => 'finished', 'status' => 'passed'];

        return implode("\n", array_map(fn (array $event) => json_encode($event), $events))."\n";
    }

    private function ran(string $command): PendingProcess
    {
        $found = null;
        Process::assertRan(function (PendingProcess $p) use ($command, &$found) {
            if ((((array) $p->command)[2] ?? null) === $command) {
                $found = $p;

                return true;
            }

            return false;
        });

        return $found;
    }

    public function test_the_call_is_scheduled_held_and_drafted_and_its_answers_reach_the_brief(): void
    {
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'onboarding-call'])
            ->expectsOutputToContain('fleets reported Acme Care (acme-care) to onboarding')
            ->expectsOutputToContain('38 lines filed as his worker files them')
            ->expectsOutputToContain("Imported onboarding session #7 into Acme Care's Brief.")
            ->expectsOutputToContain('Onboarding call passed')
            ->assertSuccessful();

        // fleets' own report of the fleet, handed to the stand-in onboarding.
        $this->assertSame(['php', 'artisan', 'fleet:report-onboarding', '--json', '--install=acme-care', '--no-interaction'], (array) $this->ran('fleet:report-onboarding')->command);
        $this->assertSame("{$this->root}/onboarding.travischapman.practice", $this->ran('onboarding:receive-fleets')->path);

        // The call: session 7, the host, drafted against the stand-in.
        $call = $this->ran('avatar:scripted-call');
        $this->assertSame(['7', '--host=journey.host@example.com', '--draft'], [((array) $call->command)[3], ((array) $call->command)[5], ((array) $call->command)[6]]);
        $this->assertSame('http://127.0.0.1:9', $call->environment['ANTHROPIC_BASE_URL']);
        $lines = json_decode(File::get(((array) $call->command)[4]), true)['lines'];
        $this->assertSame('client', $lines[2]['speaker']);
        $this->assertSame('It is Jane Doe.', collect($lines)->firstWhere('content', 'It is Jane Doe.')['content']);

        // The held session is imported, then delivered.
        $this->assertSame("{$this->root}/fleets.dashcore.com", $this->ran('fleet:onboarding-import')->path);
        $this->ran('fleet:deliver-brief');

        [$schedule, $review] = $this->walked;
        $this->assertSame('acme-care', collect($schedule['steps'])->firstWhere('target', '#fleet')['value']);
        $this->assertSame('Journey Host', collect($schedule['steps'])->firstWhere('target', '#hostId')['option']);
        $this->assertSame('/onboardings/7', collect($review['steps'])->where('do', 'visit')->last()['path']);
        $this->assertCount(18, collect($review['steps'])->filter(fn (array $step) => str_contains((string) ($step['target'] ?? ''), 'confirmProposal')));
    }

    public function test_a_session_that_did_not_end_up_held_is_never_imported(): void
    {
        $this->holdLands = false;
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'onboarding-call'])
            ->expectsOutputToContain("Acme Care's session is scheduled, not held.")
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $p) => in_array('fleet:onboarding-import', (array) $p->command, true));
    }
}
