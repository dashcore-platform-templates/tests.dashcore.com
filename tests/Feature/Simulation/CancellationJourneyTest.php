<?php

namespace Tests\Feature\Simulation;

use App\Simulation\CustomerJourneys\Cancellation;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The cancellation journey: the customer's cockpit answers, they cancel
 * their subscription on Stripe, dcos hears it through the forwarder, fleets
 * takes the fleet down (`fleet:offboard-intake`), and the journey holds when
 * dcos says offboarded and the cockpit no longer answers.
 */
class CancellationJourneyTest extends TestCase
{
    private const UUID = '0199b7a2-8f0e-7c1a-9d3e-4b5a6c7d8e9f';

    private const CLI_SECRET = 'whsec_cli_signing_secret_for_tests';

    private string $root;

    /** @var list<string> what dcos says of the request, one per ask; the last repeats */
    private array $statuses = [];

    /** @var list<array<string, mixed>> the subscriptions Stripe lists for the customer */
    private array $subscriptions = [];

    private bool $offboardRan = false;

    private bool $cancelRan = false;

    /** @var list<array<string, mixed>> Subscriptions Stripe already cancelled, for --status=canceled. */
    private array $cancelledSubscriptions = [];

    /** @var list<array<string, mixed>> */
    private array $events = [];

    private ?string $resent = null;

    /** What the cockpit's address answers once it is taken down: Herd's 404 for a folder it no longer has. */
    private int $cockpitAfter = 404;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/pg-cancellation-'.bin2hex(random_bytes(4));

        foreach (['dcos.travischapman.practice', 'ceo.acme-care.practice', 'fleets.dashcore.com'] as $checkout) {
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
            'simulation.stripe.cli' => '/opt/stripe',
        ]);

        $this->statuses = ['built', 'built', 'cancelled', 'offboarded'];
        $this->subscriptions = [['id' => 'sub_acme', 'description' => 'API + CEO — Acme Care', 'livemode' => false, 'status' => 'active']];

        $this->app->resolving(Cancellation::class, function (Cancellation $journey) {
            $journey->sleep = fn (int $seconds) => null;
            $journey->hearWithin = 1;
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /** @return array<string, mixed> what dcos says now */
    private function shown(): array
    {
        $status = count($this->statuses) > 1 ? array_shift($this->statuses) : $this->statuses[0];

        return [
            'uuid' => self::UUID, 'status' => $status, 'company' => 'Acme Care', 'company_domain' => null,
            'owner_name' => 'Jane Doe', 'owner_email' => 'jane@acmecare.test', 'apps' => ['api', 'ceo'],
            'install_slug' => 'acme-care', 'install_url' => 'http://ceo.acme-care.practice.test',
            'message' => $status === 'offboarded' ? 'Taken down: nothing of it runs any more.' : 'Built.',
            'offboarded_at' => $status === 'offboarded' ? now()->toIso8601String() : null,
        ];
    }

    private function fakeProcesses(): void
    {
        Http::fake(['http://ceo.acme-care.practice.test/*' => Http::response($this->cockpitAfter === 200 ? 'Sign in' : 'Not Found', $this->cockpitAfter)]);

        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;

            if (($command[1] ?? null) === '-r') {
                return Process::result("\n@@simulation-config@@".json_encode([
                    'mail.default' => 'log',
                    'starts_with:services.stripe.secret' => true,
                    'sha256:services.stripe.webhook_secret' => hash('sha256', self::CLI_SECRET),
                ])."\n");
            }

            if ($command[0] === '/opt/stripe') {
                return match ($command[1].' '.$command[2]) {
                    'listen --print-secret' => Process::result(self::CLI_SECRET."\n"),
                    'customers list' => Process::result(json_encode(['data' => [['id' => 'cus_acme', 'email' => 'jane@acmecare.test']]])),
                    'subscriptions list' => Process::result(json_encode(['data' => in_array('--status=canceled', $command, true) ? $this->cancelledSubscriptions : $this->subscriptions])),
                    'events list' => Process::result(json_encode(['data' => $this->events])),
                    'events resend' => ($this->resent = $command[3]) ? Process::result(json_encode(['id' => $command[3]])) : null,
                    // As the real CLI answers: which account first, then the object.
                    'subscriptions cancel' => ($this->cancelRan = true) ? Process::result("This command will be executed on the account with the following details:\n> Mode: Test\n> Account Name: Dashcore\n\n".json_encode(['id' => $command[3], 'status' => 'canceled', 'livemode' => false])) : null,
                    default => Process::result('', "> Ready! Your webhook signing secret is ... (^C to quit)\n"),
                };
            }

            return match ($command[2] ?? null) {
                'installs:show' => Process::result(json_encode($this->shown())."\n"),
                'fleet:offboard-intake' => ($this->offboardRan = true) ? Process::result("  INFO  Acme Care is taken down, and Practice (travischapman)'s dcos says it is offboarded.\n") : null,
                default => Process::result($this->everyStepPasses((string) $process->input)),
            };
        });
    }

    private function everyStepPasses(string $input): string
    {
        $steps = (array) (json_decode($input, true)['steps'] ?? []);
        $events = [['event' => 'started']];

        foreach (array_keys($steps) as $i) {
            $events[] = ['event' => 'step', 'position' => $i, 'status' => 'passed', 'ms' => 50, 'url' => 'http://ceo.acme-care.practice.test/login', 'error' => null];
        }

        $events[] = ['event' => 'finished', 'status' => 'passed'];

        return implode("\n", array_map(fn (array $event) => json_encode($event), $events))."\n";
    }

    /** @return array{0: int, 1: string} its exit code, and what it printed with the console's wrapping undone */
    private function journey(): array
    {
        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call('tests:journey', ['journey' => 'cancellation']);

        return [$exit, (string) preg_replace('/\s+/', ' ', Artisan::output())];
    }

    public function test_the_customer_cancels_dcos_hears_it_fleets_takes_the_fleet_down_and_the_cockpit_is_gone(): void
    {
        $this->fakeProcesses();

        [$exit, $output] = $this->journey();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('They cancel API + CEO — Acme Care (sub_acme) on Stripe.', $output);
        $this->assertStringContainsString('dcos has it cancelled.', $output);
        $this->assertStringContainsString('dcos says it is offboarded', $output);
        $this->assertStringContainsString('http://ceo.acme-care.practice.test no longer answers.', $output);
        $this->assertStringContainsString('Cancellation passed', $output);

        // Only the cancellation is forwarded to dcos while it runs.
        Process::assertRan(fn (PendingProcess $p) => (array) $p->command === [
            '/opt/stripe', 'listen',
            '--forward-to', 'http://dcos.travischapman.practice.test/webhooks/stripe',
            '--events', 'customer.subscription.deleted',
        ]);
        // Cancelled now, in test mode; never --live.
        Process::assertRan(fn (PendingProcess $p) => (array) $p->command === ['/opt/stripe', 'subscriptions', 'cancel', 'sub_acme', '--confirm']);
        Process::assertDidntRun(fn (PendingProcess $p) => in_array('--live', (array) $p->command, true));
        // fleets takes in the practice fleet's cancellations, in its own checkout under an empty environment.
        Process::assertRan(fn (PendingProcess $p) => $p->path === "{$this->root}/fleets.dashcore.com"
            && (array) $p->command === ['php', 'artisan', 'fleet:offboard-intake', 'practice-travischapman', '--no-interaction']
            && ($p->environment['DB_DATABASE'] ?? null) === false);

        // The cockpit answered before, through the browser, and was asked again after.
        Http::assertSent(fn ($request) => $request->url() === 'http://ceo.acme-care.practice.test/login');
    }

    public function test_a_subscription_an_earlier_run_cancelled_has_its_cancellation_sent_to_dcos_again(): void
    {
        // The first live run cancelled it and stopped before its forwarder
        // delivered the event; dcos still had the fleet as built.
        $this->subscriptions = [];
        $this->cancelledSubscriptions = [['id' => 'sub_acme', 'description' => 'API + CEO — Acme Care', 'livemode' => false, 'status' => 'canceled']];
        $this->events = [['id' => 'evt_deleted', 'livemode' => false, 'data' => ['object' => ['id' => 'sub_acme']]]];
        $this->fakeProcesses();

        [$exit, $output] = $this->journey();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('is already cancelled on Stripe; it sends the cancellation to dcos again.', $output);
        $this->assertSame('evt_deleted', $this->resent);
        $this->assertFalse($this->cancelRan);
    }

    public function test_a_dcos_that_never_hears_the_cancellation_fails_before_fleets_is_asked(): void
    {
        $this->statuses = ['built'];
        $this->fakeProcesses();

        [$exit, $output] = $this->journey();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('dcos never heard the subscription end', $output);
        $this->assertTrue($this->cancelRan);
        $this->assertFalse($this->offboardRan);
    }

    public function test_a_fleet_fleets_did_not_take_down_fails_with_what_dcos_says(): void
    {
        $this->statuses = ['built', 'built', 'cancelled', 'offboarding'];
        $this->fakeProcesses();

        [$exit, $output] = $this->journey();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString("fleets did not take Acme Care's fleet down: dcos has it offboarding", $output);
    }

    public function test_a_cockpit_still_answering_after_offboarding_fails_the_journey(): void
    {
        $this->cockpitAfter = 200;
        $this->fakeProcesses();

        [$exit, $output] = $this->journey();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('still answers', $output);
    }

    public function test_it_cancels_nothing_unless_exactly_one_test_subscription_matches(): void
    {
        $this->subscriptions = [
            ['id' => 'sub_live', 'description' => 'API + CEO — Acme Care', 'livemode' => true],
            ['id' => 'sub_other', 'description' => 'Managed Hosting', 'livemode' => false],
        ];
        $this->fakeProcesses();

        [$exit, $output] = $this->journey();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Stripe has 0 active test API + CEO subscription(s) for jane@acmecare.test', $output);
        $this->assertFalse($this->cancelRan);
        $this->assertFalse($this->offboardRan);
    }

    public function test_only_a_practice_fleets_customer_is_cancelled_here(): void
    {
        config(['simulation.fleets.practice.install' => 'dashcore']);
        $this->fakeProcesses();

        [$exit, $output] = $this->journey();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('is not a practice fleet', $output);
        $this->assertFalse($this->cancelRan);
    }
}
