<?php

namespace Tests\Feature\Simulation;

use App\Simulation\CustomerJourneys\Enquiry;
use App\Simulation\Persona;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class RunJourneyTest extends TestCase
{
    private string $root;

    /** @var array<string, string> what each checkout's config says, by checkout folder */
    private array $mail = [];

    /** @var list<array<string, mixed>> what the browser reports */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/pg-journey-'.bin2hex(random_bytes(4));

        foreach (['travischapman.practice', 'sales.travischapman.practice', 'sales.dashcore.com'] as $checkout) {
            File::ensureDirectoryExists("{$this->root}/{$checkout}");
        }

        config([
            'simulation.screenshots' => "{$this->root}/shots",
            'simulation.fleets.practice.apps.website.checkout' => "{$this->root}/travischapman.practice",
            'simulation.fleets.practice.apps.website.base_url' => 'http://travischapman.practice.test',
            'simulation.fleets.practice.apps.sales.checkout' => "{$this->root}/sales.travischapman.practice",
            'simulation.fleets.practice.apps.sales.base_url' => 'http://sales.travischapman.practice.test',
        ]);

        $safe = ['mail.default' => 'log', 'app.env' => 'local', 'mail.local_really_send' => false];
        $this->mail = ['travischapman.practice' => json_encode($safe), 'sales.travischapman.practice' => json_encode($safe)];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * Every process the command starts, answered as the real one would: the
     * config read, the rep being made, and the browser.
     */
    private function fakeProcesses(): void
    {
        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;

            if (($command[1] ?? null) === '-r') {
                return Process::result("\n@@simulation-config@@".$this->mail[basename((string) $process->path)]."\n");
            }

            if (($command[2] ?? null) === 'dashcore:admin') {
                return Process::result("   INFO  Created journey.rep@example.com as member.\n");
            }

            return Process::result(implode("\n", array_map(fn (array $event) => json_encode($event), $this->events))."\n");
        });
    }

    /**
     * The browser walking the enquiry, every step passing, having read the
     * prospect's email back off the website's form.
     *
     * @return list<array<string, mixed>>
     */
    private function aWalk(int $steps, string $email, ?int $failAt = null): array
    {
        $events = [['event' => 'started']];

        for ($i = 0; $i < $steps; $i++) {
            if ($i === 4) {
                $events[] = ['event' => 'remember', 'position' => $i, 'name' => 'email', 'value' => $email];
            }

            $failed = $i === $failAt;
            $events[] = ['event' => 'step', 'position' => $i, 'status' => 'running'];
            $events[] = ['event' => 'step', 'position' => $i, 'status' => $failed ? 'failed' : 'passed', 'ms' => 120, 'url' => 'http://x.test/', 'error' => $failed ? 'Timeout 15000ms exceeded.' : null];

            if ($failed) {
                break;
            }
        }

        $events[] = ['event' => 'finished', 'status' => $failAt === null ? 'passed' : 'failed'];

        return $events;
    }

    private function enquirySteps(): int
    {
        // Website steps, the sign-in, and the search in sales.
        $script = app(Enquiry::class)->script(['sales' => new Persona('Journey Rep', 'journey.rep@example.com', 'x', 'Sales rep')], fake());

        return count($script->steps());
    }

    public function test_the_list_is_the_progress_bar_for_the_whole_customer_journey(): void
    {
        $this->artisan('tests:journey')
            ->expectsOutputToContain('1 of 7 stages simulated')
            ->expectsOutputToContain('enquiry')
            ->expectsOutputToContain('cancellation')
            ->assertSuccessful();

        $this->assertSame(
            ['enquiry', 'payment', 'provisioning', 'first-sign-in', 'first-briefing', 'upgrade', 'cancellation'],
            array_keys(config('simulation.customer_journeys')),
        );
    }

    public function test_a_pending_stage_says_so_and_does_not_pass(): void
    {
        Process::fake();

        $this->artisan('tests:journey', ['journey' => 'payment'])
            ->expectsOutputToContain('pending')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_it_refuses_a_fleet_app_that_is_not_a_stand_in(): void
    {
        $this->fakeProcesses();
        config(['simulation.fleets.practice.apps.sales.checkout' => "{$this->root}/sales.dashcore.com"]);

        $this->artisan('tests:journey', ['journey' => 'enquiry'])
            ->expectsOutputToContain('is not a stand-in app')
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $p) => in_array('dashcore:admin', (array) $p->command, true));
        Process::assertDidntRun(fn (PendingProcess $p) => ((array) $p->command)[0] === config('simulation.node'));
    }

    public function test_it_refuses_to_walk_when_an_app_would_send_real_mail(): void
    {
        $this->fakeProcesses();
        $this->mail['sales.travischapman.practice'] = json_encode(['mail.default' => 'resend']);

        $this->artisan('tests:journey', ['journey' => 'enquiry'])
            ->expectsOutputToContain('mail.default is "resend", and must be "log"')
            ->assertFailed();

        // Nobody is made and no browser opens: the guard comes first.
        Process::assertDidntRun(fn (PendingProcess $p) => in_array('dashcore:admin', (array) $p->command, true));
        Process::assertDidntRun(fn (PendingProcess $p) => ((array) $p->command)[0] === config('simulation.node'));
    }

    public function test_it_refuses_when_the_website_would_really_send(): void
    {
        $this->fakeProcesses();
        $this->mail['travischapman.practice'] = json_encode(['mail.default' => 'log', 'app.env' => 'local', 'mail.local_really_send' => true]);

        $this->artisan('tests:journey', ['journey' => 'enquiry'])
            ->expectsOutputToContain('mail.local_really_send is true')
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $p) => ((array) $p->command)[0] === config('simulation.node'));
    }

    public function test_it_walks_the_enquiry_across_the_website_and_sales_carrying_the_prospects_email(): void
    {
        $email = 'journey+abc123def456@example.com';
        $this->events = $this->aWalk($this->enquirySteps(), $email);
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'enquiry', '--target' => 'practice'])
            ->expectsOutputToContain('The prospect opens the contact page')
            // A label written as {email} names the address read off the form.
            ->expectsOutputToContain("searches for {$email}")
            ->expectsOutputToContain('Enquiry passed')
            ->assertSuccessful();

        // The rep is made by sales' own command, in its checkout, with a
        // password for this run, under an empty environment.
        Process::assertRan(fn (PendingProcess $p) => $p->path === "{$this->root}/sales.travischapman.practice"
            && array_slice((array) $p->command, 0, 5) === ['php', 'artisan', 'dashcore:admin', 'journey.rep@example.com', '--name=Journey Rep']
            && in_array('--role=member', (array) $p->command, true)
            && ($p->environment['DB_DATABASE'] ?? null) === false);

        $walked = null;
        Process::assertRan(function (PendingProcess $p) use (&$walked) {
            if (((array) $p->command)[0] !== config('simulation.node')) {
                return false;
            }

            $walked = json_decode((string) $p->input, true);

            return true;
        });

        $steps = collect($walked['steps']);
        $this->assertSame(['website' => 'http://travischapman.practice.test', 'sales' => 'http://sales.travischapman.practice.test'], $walked['base_urls']);
        $this->assertSame('http://travischapman.practice.test', $walked['base_url']);

        // Website first, then sales; the email crosses between them as {email}.
        $this->assertSame(['website', '/lets-talk'], [$steps->first()['on'], $steps->first()['path']]);
        $this->assertSame('sales', $steps->last()['on']);
        $this->assertTrue($steps->contains(fn ($s) => $s['do'] === 'remember' && $s['target'] === '#df-email' && $s['as'] === 'email' && $s['on'] === 'website'));
        $this->assertTrue($steps->contains(fn ($s) => $s['do'] === 'fill' && $s['target'] === '#leadSearch' && $s['value'] === '{email}' && $s['on'] === 'sales'));

        // The honeypot is never touched.
        $this->assertFalse($steps->contains(fn ($s) => ($s['target'] ?? null) === '#df-website'));

        // The rep signs in with the password sales was just told to give them.
        $password = $steps->firstWhere('target', '#password')['value'];
        $this->assertSame(24, strlen($password));
        Process::assertRan(fn (PendingProcess $p) => in_array("--password={$password}", (array) $p->command, true));
    }

    public function test_every_prospect_is_new_and_on_example_com(): void
    {
        $journey = app(Enquiry::class);
        $rep = ['sales' => new Persona('Journey Rep', 'journey.rep@example.com', 'x', 'Sales rep')];

        $emails = collect(range(1, 5))->map(fn () => collect($journey->script($rep, fake())->steps())->firstWhere('target', '#df-email')['value']);

        $this->assertCount(5, $emails->unique());
        $emails->each(fn (string $email) => $this->assertMatchesRegularExpression('/^journey\+[a-z0-9]{12}@example\.com$/', $email));
    }

    public function test_a_failed_step_fails_the_command_and_says_where(): void
    {
        $this->events = $this->aWalk($this->enquirySteps(), 'journey+x@example.com', failAt: 10);
        $this->fakeProcesses();

        $this->artisan('tests:journey', ['journey' => 'enquiry'])
            ->expectsOutputToContain('Timeout 15000ms exceeded.')
            ->expectsOutputToContain('(not reached)')
            ->expectsOutputToContain('Enquiry failed after 10 of')
            ->assertFailed();
    }

    public function test_a_browser_that_dies_fails_the_command(): void
    {
        $this->fakeProcesses();
        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;

            return match (true) {
                ($command[1] ?? null) === '-r' => Process::result("@@simulation-config@@{$this->mail[basename((string) $process->path)]}\n"),
                ($command[2] ?? null) === 'dashcore:admin' => Process::result('ok'),
                default => Process::result('', 'Error: browserType.launch: Executable doesn\'t exist', 1),
            };
        });

        $this->artisan('tests:journey', ['journey' => 'enquiry'])
            ->expectsOutputToContain('Executable doesn\'t exist')
            ->assertFailed();
    }

    public function test_an_unknown_journey_or_fleet_is_refused(): void
    {
        Process::fake();

        $this->artisan('tests:journey', ['journey' => 'nope'])->assertFailed();
        $this->artisan('tests:journey', ['journey' => 'enquiry', '--target' => 'nope'])->assertFailed();

        Process::assertNothingRan();
    }
}
