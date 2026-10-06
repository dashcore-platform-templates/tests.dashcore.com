<?php

namespace Tests\Feature\Simulation;

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

        foreach (['dcos.travischapman.practice', 'ceo.acme-care.practice', 'ceo.acme.example', 'fleets.dashcore.com'] as $checkout) {
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
        $this->mail = ['dcos.travischapman.practice' => $safe, 'ceo.acme-care.practice' => $safe];

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

    public function test_first_sign_in_reads_the_invitation_from_the_cockpits_mail_log_and_signs_in_with_a_password_of_their_own(): void
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
}
