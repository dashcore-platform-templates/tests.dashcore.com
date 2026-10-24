<?php

namespace Tests\Feature\Simulation;

use App\Simulation\Provisioning\OwnerSignIn;
use App\Simulation\StandIns\AnthropicStandIn;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Journeys 3 and 4: the paid order builds the customer's own fleet, and its
 * owner signs in to it. fleets builds it (`fleet:intake`), dcos is where the
 * journey reads back where it was built, and the customer's cockpit — an app
 * no config can name in advance — is guarded like every other stand-in
 * before the browser goes near it.
 */
class ProvisioningJourneyTest extends TestCase
{
    private const UUID = '0199b7a2-8f0e-7c1a-9d3e-4b5a6c7d8e9f';

    private string $root;

    /** @var array<string, string> what each checkout's config says, by checkout folder */
    private array $mail = [];

    /** @var list<array<string, mixed>> the requests dcos lists as waiting */
    private array $pending = [];

    /** @var array<string, mixed> what dcos says of the request once fleets has reported */
    private array $shown = [];

    private bool $intakeSucceeds = true;

    private bool $intakeRan = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/pg-provisioning-'.bin2hex(random_bytes(4));

        foreach (['dcos.travischapman.practice', 'ceo.acme-care.practice', 'login.acme-care.practice', 'ceo.acme.example', 'fleets.dashcore.com'] as $checkout) {
            File::ensureDirectoryExists("{$this->root}/{$checkout}");
        }
        File::put("{$this->root}/fleets.dashcore.com/artisan", '');

        config([
            'simulation.screenshots' => "{$this->root}/shots",
            'simulation.fleets.practice.install' => 'practice-travischapman',
            'simulation.fleets.practice.apps.delivery.checkout' => "{$this->root}/dcos.travischapman.practice",
            'simulation.fleets.practice.apps.delivery.base_url' => 'http://dcos.travischapman.practice.test',
            'simulation.fleets.practice.fleets_checkout' => "{$this->root}/fleets.dashcore.com",
            'simulation.fleets.practice.client_root' => $this->root,
        ]);

        $safe = json_encode(['mail.default' => 'log']);
        $this->mail = ['dcos.travischapman.practice' => $safe, 'ceo.acme-care.practice' => $safe, 'login.acme-care.practice' => $safe];

        $request = [
            'uuid' => self::UUID,
            'status' => 'awaiting',
            'company' => 'Acme Care',
            'company_domain' => null,
            'owner_name' => 'Jane Doe',
            'owner_email' => 'jane@acmecare.test',
            'apps' => ['api', 'ceo'],
            'requested_at' => now()->toIso8601String(),
        ];
        $this->pending = [$request];
        $this->shown = [...$request, 'status' => 'built', 'install_slug' => 'acme-care', 'install_url' => 'http://ceo.acme-care.practice.test', 'message' => 'Built.'];
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
                // dcos takes payments, so it is asked about its Stripe keys too.
                $answers = str_contains($command[2], "'starts_with'")
                    ? json_encode(['starts_with:services.stripe.secret' => true])
                    : ($this->mail[basename((string) $process->path)] ?? json_encode(['mail.default' => 'smtp']));

                return Process::result("\n@@simulation-config@@{$answers}\n");
            }

            return match ($command[2] ?? null) {
                'installs:pending' => Process::result(json_encode(['installs' => $this->pending])."\n"),
                'installs:show' => Process::result(json_encode($this->shown)."\n"),
                'assistant:brief' => Process::result("Welcome to your cockpit, Jane\n  Morning briefing · 1 sections · generated 1 second ago\n"),
                'fleet:intake' => ($this->intakeRan = true) && $this->intakeSucceeds
                    ? Process::result("  Acme Care is built at http://ceo.acme-care.practice.test, and jane@acmecare.test was invited.\n")
                    : Process::result("  ERROR  Acme Care did not finish building.\n", '', 1),
                // The browser: every step it was given passes.
                default => Process::result($this->everyStepPasses((string) $process->input)),
            };
        });
    }

    /** @return array<string, mixed> what the browser was asked to walk */
    private function walked(): array
    {
        $walked = null;
        Process::assertRan(function (PendingProcess $p) use (&$walked) {
            if (((array) $p->command)[0] !== config('simulation.node')) {
                return false;
            }

            $walked = json_decode((string) $p->input, true);

            return true;
        });

        return $walked;
    }

    private function intakeRan(): bool
    {
        return $this->intakeRan;
    }

    private function everyStepPasses(string $input): string
    {
        $steps = (array) (json_decode($input, true)['steps'] ?? []);
        $events = [['event' => 'started']];

        foreach (array_keys($steps) as $i) {
            $events[] = ['event' => 'step', 'position' => $i, 'status' => 'passed', 'ms' => 50, 'url' => 'http://ceo.acme-care.practice.test/', 'error' => null];
        }

        $events[] = ['event' => 'finished', 'status' => 'passed'];

        return implode("\n", array_map(fn (array $event) => json_encode($event), $events))."\n";
    }

    public function test_provisioning_has_fleets_build_the_paid_order_and_opens_the_cockpit_dcos_reports(): void
    {
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'provisioning'])
            ->expectsOutputToContain('Acme Care paid for API + CEO, for jane@acmecare.test')
            ->expectsOutputToContain('Acme Care is built at')
            ->expectsOutputToContain('Provisioning passed')
            ->assertSuccessful();

        // fleets is asked to take in the practice fleet's sales, in its own
        // checkout, under an empty environment.
        Process::assertRan(fn (PendingProcess $p) => $p->path === "{$this->root}/fleets.dashcore.com"
            && (array) $p->command === ['php', 'artisan', 'fleet:intake', 'practice-travischapman', '--no-interaction']
            && ($p->environment['DB_DATABASE'] ?? null) === false);
        Process::assertRan(fn (PendingProcess $p) => (array) $p->command === ['php', 'artisan', 'installs:show', self::UUID, '--json', '--no-interaction']);

        $walked = $this->walked();
        $this->assertSame('http://ceo.acme-care.practice.test', $walked['base_urls']['cockpit']);
        $this->assertSame("{$this->root}/ceo.acme-care.practice/storage/logs", $walked['mail_logs']['cockpit']);
        $this->assertSame(['cockpit', '/login'], [$walked['steps'][0]['on'], $walked['steps'][0]['path']]);
    }

    public function test_with_nothing_paid_for_it_says_how_to_get_there_and_builds_nothing(): void
    {
        $this->pending = [];
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'provisioning'])
            ->expectsOutputToContain('No paid API + CEO fleet is waiting')
            ->assertFailed();

        $this->assertFalse($this->intakeRan());
        Process::assertDidntRun(fn (PendingProcess $p) => ((array) $p->command)[0] === config('simulation.node'));
    }

    public function test_a_build_that_did_not_finish_fails_with_what_dcos_was_told(): void
    {
        $this->intakeSucceeds = false;
        $this->shown = [...$this->shown, 'status' => 'failed', 'install_url' => null, 'message' => 'The build stopped at ceo deploy-app.'];
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'provisioning'])
            ->expectsOutputToContain('The build stopped at ceo deploy-app.')
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $p) => ((array) $p->command)[0] === config('simulation.node'));
    }

    public function test_a_fleet_built_anywhere_but_practice_here_is_never_driven(): void
    {
        $this->shown['install_url'] = 'https://ceo.acme-care.laravel.cloud';
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'provisioning'])
            ->expectsOutputToContain('not a practice fleet on this machine')
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $p) => ((array) $p->command)[0] === config('simulation.node'));
    }

    public function test_a_cockpit_that_would_send_real_mail_is_refused(): void
    {
        $this->mail['ceo.acme-care.practice'] = json_encode(['mail.default' => 'resend']);
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'provisioning'])
            ->expectsOutputToContain('mail.default is "resend", and must be "log"')
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $p) => ((array) $p->command)[0] === config('simulation.node'));
    }

    public function test_only_a_practice_fleet_has_a_fleet_built_for_its_customer(): void
    {
        config(['simulation.fleets.practice.install' => 'dashcore']);
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'provisioning'])
            ->expectsOutputToContain('is not a practice fleet')
            ->assertFailed();

        $this->assertFalse($this->intakeRan());
    }

    public function test_dcos_must_keep_its_mail_too_before_anything_is_asked_of_it(): void
    {
        $this->mail['dcos.travischapman.practice'] = json_encode(['mail.default' => 'smtp']);
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'provisioning'])->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $p) => in_array('installs:pending', (array) $p->command, true));
        $this->assertFalse($this->intakeRan());
    }

    public function test_first_sign_in_reads_ceos_own_link_when_the_build_fell_back_and_signs_in_with_a_password_of_their_own(): void
    {
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'first-sign-in'])
            ->expectsOutputToContain('its owner is jane@acmecare.test')
            ->expectsOutputToContain('First sign-in passed')
            ->assertSuccessful();

        // It builds nothing: it reads the newest fleet dcos was told is built.
        $this->assertFalse($this->intakeRan());
        Process::assertRan(fn (PendingProcess $p) => (array) $p->command === ['php', 'artisan', 'installs:show', '--json', '--no-interaction']);

        $walked = $this->walked();
        $steps = collect($walked['steps']);

        $this->assertSame('http://ceo.acme-care.practice.test', $walked['base_urls']['cockpit']);
        $this->assertTrue($steps->every(fn ($s) => $s['on'] === 'cockpit'));

        $mail = $steps->firstWhere('do', 'mail');
        $this->assertSame('jane@acmecare.test', $mail['to']);
        $this->assertSame('set_password_link', $mail['as']);
        $this->assertSame('{set_password_link}', $steps->firstWhere('do', 'visit')['path']);

        // The password they choose is the one they sign in with.
        $chosen = $steps->where('target', '#password')->pluck('value')->unique();
        $this->assertCount(1, $chosen);
        $this->assertSame($chosen->first(), $steps->firstWhere('target', '#password_confirmation')['value']);
        $this->assertSame('jane@acmecare.test', $steps->firstWhere('target', '#email')['value']);
        $this->assertSame(', Jane', $steps->last(fn ($s) => $s['do'] === 'see')['text']);

        // The pattern finds the link the cockpit mails (a JS regex, written so PHP reads it the same).
        $link = 'http://ceo.acme-care.practice.test/reset-password/'.str_repeat('a1', 32).'?email=jane%40acmecare.test';
        $this->assertSame(1, preg_match('~'.$mail['pattern'].'~', "Set your password: {$link}\n", $found));
        $this->assertSame($link, $found[0]);
    }

    public function test_first_sign_in_waits_for_a_built_fleet(): void
    {
        $this->shown = [...$this->shown, 'status' => 'building', 'install_url' => null, 'message' => 'Being built.'];
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'first-sign-in'])
            ->expectsOutputToContain('is not built: dcos has it building')
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $p) => ((array) $p->command)[0] === config('simulation.node'));
    }

    public function test_first_sign_in_takes_logins_invitation_when_login_sent_one(): void
    {
        File::ensureDirectoryExists("{$this->root}/login.acme-care.practice/storage/logs");
        File::put("{$this->root}/login.acme-care.practice/storage/logs/laravel.log", "To: jane@acmecare.test\nSet up your sign-in: http://login.acme-care.practice.test/invitation/abc123?email=jane%40acmecare.test\n");
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'first-sign-in'])
            ->expectsOutputToContain('login invited them')
            ->expectsOutputToContain('First sign-in passed')
            ->assertSuccessful();

        $walked = $this->walked();
        $steps = collect($walked['steps']);
        $this->assertSame('http://login.acme-care.practice.test', $walked['base_urls']['login']);
        $this->assertSame('login', $steps->first()['on']);

        $mail = $steps->firstWhere('do', 'mail');
        $this->assertSame('invitation_link', $mail['as']);
        $link = 'http://login.acme-care.practice.test/invitation/AbC123xyz?email=jane%40acmecare.test';
        $this->assertSame(1, preg_match('~'.$mail['pattern'].'~', "Set up your sign-in: {$link}\n", $found));
        $this->assertSame($link, $found[0]);

        // The password they chose is kept for the stages after, with the door.
        $kept = app(OwnerSignIn::class)->of('acme-care');
        $this->assertSame(OwnerSignIn::LOGIN, $kept['door']);
        $this->assertSame($steps->firstWhere('target', 'input[wire\\:model="password"]')['value'], $kept['password']);
    }

    public function test_a_fleet_on_the_practice_zone_is_driven_by_its_public_name_from_its_practice_folder(): void
    {
        config(['simulation.practice_zone' => 'practice.dashcore.com']);
        $this->shown['install_url'] = 'https://ceo.acme-care.practice.dashcore.com';
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'first-sign-in'])->assertSuccessful();

        $walked = $this->walked();
        $this->assertSame('https://ceo.acme-care.practice.dashcore.com', $walked['base_urls']['cockpit']);
        $this->assertSame('https://login.acme-care.practice.dashcore.com', $walked['base_urls']['login']);
        $this->assertSame("{$this->root}/ceo.acme-care.practice/storage/logs", $walked['mail_logs']['cockpit']);
    }

    public function test_first_briefing_has_ceo_write_it_against_the_stand_in_and_the_owner_finds_it(): void
    {
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

            public function requests(): array
            {
                return [['messages' => []], ['messages' => []]];
            }
        });
        app(OwnerSignIn::class)->keep('acme-care', OwnerSignIn::CEO, 'jane@acmecare.test', 'a-password-of-her-own');
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'first-briefing'])
            ->expectsOutputToContain('wrote it against the Anthropic stand-in')
            ->expectsOutputToContain('Its headline: Welcome to your cockpit, Jane')
            ->expectsOutputToContain('First briefing passed')
            ->assertSuccessful();

        // ceo's own step, in its checkout, pointed at the stand-in.
        Process::assertRan(fn (PendingProcess $p) => $p->path === "{$this->root}/ceo.acme-care.practice"
            && (array) $p->command === ['php', 'artisan', 'assistant:brief', '--no-interaction']
            && ($p->environment['ANTHROPIC_BASE_URL'] ?? null) === 'http://127.0.0.1:9'
            && ($p->environment['ANTHROPIC_API_KEY'] ?? null) === 'stand-in');

        $steps = collect($this->walked()['steps']);
        $this->assertSame('/login', $steps->firstWhere('do', 'visit')['path']);
        $this->assertSame('a-password-of-her-own', $steps->firstWhere('target', 'input[type="password"]')['value']);
        $this->assertSame('Welcome to your cockpit, Jane', $steps->last(fn ($s) => $s['do'] === 'see')['text']);
    }

    public function test_first_briefing_waits_for_the_owners_first_sign_in(): void
    {
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'first-briefing'])
            ->expectsOutputToContain('Walk `tests:journey first-sign-in` first')
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $p) => in_array('assistant:brief', (array) $p->command, true));
    }
}
