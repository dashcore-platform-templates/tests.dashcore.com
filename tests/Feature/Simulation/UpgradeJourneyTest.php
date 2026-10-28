<?php

namespace Tests\Feature\Simulation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The upgrade journey: fleets puts the customer's cockpit on an older
 * Templates release, the owner sees the upgrade offered, fleets pushes the
 * newer one, and the owner sees it arrive. Two walks with a push between
 * them (CustomerJourney::next), and a cockpit that could not know of a newer
 * release refused before anybody signs in.
 */
class UpgradeJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '0199b7a2-8f0e-7c1a-9d3e-4b5a6c7d8e9f';

    private string $root;

    /** @var array<string, mixed> what the cockpit's config says */
    private array $cockpitConfig = [];

    /** @var array<string, mixed> what dcos says of the newest request */
    private array $shown = [];

    /** @var list<list<string>> every fleet:push, as [install, app, release] */
    private array $pushes = [];

    /** @var list<array<string, mixed>> what the browser was asked to walk, in order */
    private array $walks = [];

    private bool $pushSucceeds = true;

    private ?string $failingStep = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/pg-upgrade-'.bin2hex(random_bytes(4));

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
            'simulation.fleets.practice.upgrade' => ['app' => 'ceo', 'from' => 'v1.0.0', 'to' => 'v1.1.0'],
            'simulation.fleets.practice.releases_owner' => 'dashcore-platform-templates',
        ]);

        $this->cockpitConfig = [
            'mail.default' => 'log',
            'release.repository' => 'dashcore-platform-templates/ceo.dashcore.com',
            'starts_with:release.token' => true,
        ];

        $this->shown = [
            'uuid' => self::UUID,
            'status' => 'built',
            'company' => 'Acme Care',
            'company_domain' => null,
            'owner_name' => 'Jane Doe',
            'owner_email' => 'jane@acmecare.test',
            'apps' => ['api', 'ceo'],
            'install_slug' => 'acme-care',
            'install_url' => 'http://ceo.acme-care.practice.test',
            'message' => 'Built.',
        ];
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
                $answers = basename((string) $process->path) === 'ceo.acme-care.practice'
                    ? $this->cockpitConfig
                    // dcos takes payments, so it is asked about its Stripe keys too.
                    : ['mail.default' => 'log', 'starts_with:services.stripe.secret' => true];

                return Process::result("\n@@simulation-config@@".json_encode($answers)."\n");
            }

            if (($command[2] ?? null) === 'fleet:push') {
                $this->pushes[] = array_slice($command, 3, 3);

                return $this->pushSucceeds
                    ? Process::result("  Acme Care ceo runs {$command[5]}.\n")
                    : Process::result("  ERROR  ceo is pinned to {$command[5]} but not proven to run it.\n", '', 1);
            }

            return match ($command[2] ?? null) {
                'installs:show' => Process::result(json_encode($this->shown)."\n"),
                'install:check-release' => Process::result("Running: 1.0.0\nA newer release is available: 1.1.0\n"),
                'app:make-admin' => Process::result("  Account jane@acmecare.test\n"),
                default => Process::result($this->walk((string) $process->input)),
            };
        });
    }

    /** The browser: every step passes, unless one is told to fail. */
    private function walk(string $input): string
    {
        $walked = json_decode($input, true);
        $this->walks[] = $walked;
        $events = [['event' => 'started']];

        foreach ((array) ($walked['steps'] ?? []) as $i => $step) {
            $failed = $this->failingStep !== null && ($step['text'] ?? null) === $this->failingStep;
            $events[] = ['event' => 'step', 'position' => $i, 'status' => $failed ? 'failed' : 'passed', 'ms' => 50, 'url' => 'http://ceo.acme-care.practice.test/settings/system', 'error' => $failed ? 'Not on the page.' : null];

            if ($failed) {
                $events[] = ['event' => 'finished', 'status' => 'failed'];

                return implode("\n", array_map(fn (array $event) => json_encode($event), $events))."\n";
            }
        }

        $events[] = ['event' => 'finished', 'status' => 'passed'];

        return implode("\n", array_map(fn (array $event) => json_encode($event), $events))."\n";
    }

    /**
     * Walk the journey and say what it printed, its lines rejoined: the
     * console wraps a long refusal wherever the terminal is narrow.
     *
     * @return array{0: int, 1: string}
     */
    private function journey(): array
    {
        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call('tests:journey', ['journey' => 'upgrade']);

        return [$exit, (string) preg_replace('/\s+/', ' ', Artisan::output())];
    }

    /** @return list<string> what a walk looked for on the page */
    private function seen(array $walk): array
    {
        return collect($walk['steps'])->where('do', 'see')->pluck('text')->all();
    }

    public function test_fleets_puts_the_cockpit_on_the_older_release_the_owner_sees_the_upgrade_offered_then_arrive(): void
    {
        $this->fakeProcesses();

        [$exit, $output] = $this->journey();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString("Acme Care's fleet is acme-care; fleets puts its ceo on v1.0.0 first.", $output);
        $this->assertStringContainsString('fleets pushes v1.1.0 to acme-care\'s ceo.', $output);
        $this->assertStringContainsString('Upgrade passed', $output);
        $this->assertStringContainsString('moved from 1.0.0 to 1.1.0 through fleet:push', $output);

        // fleets moves it, in its own checkout under an empty environment: older first, then newer.
        $this->assertSame([['acme-care', 'ceo', 'v1.0.0'], ['acme-care', 'ceo', 'v1.1.0']], $this->pushes);
        Process::assertRan(fn (PendingProcess $p) => $p->path === "{$this->root}/fleets.dashcore.com"
            && ((array) $p->command)[2] === 'fleet:push'
            && ($p->environment['DB_DATABASE'] ?? null) === false);

        // The cockpit looks its release up afresh after each push, and the
        // owner is given a password for this run by the cockpit itself.
        Process::assertRanTimes(fn (PendingProcess $p) => $p->path === "{$this->root}/ceo.acme-care.practice"
            && (array) $p->command === ['php', 'artisan', 'install:check-release', '--fresh', '--no-interaction'], 2);
        Process::assertRan(fn (PendingProcess $p) => $p->path === "{$this->root}/ceo.acme-care.practice"
            && array_slice((array) $p->command, 0, 4) === ['php', 'artisan', 'app:make-admin', 'jane@acmecare.test']);

        $this->assertCount(2, $this->walks);
        [$before, $after] = $this->walks;

        $this->assertSame('http://ceo.acme-care.practice.test', $before['base_urls']['cockpit']);
        $this->assertContains('/settings/system', collect($before['steps'])->where('do', 'visit')->pluck('path')->all());
        $this->assertSame([', Jane', '1.0.0', 'Upgrade available', '1.1.0'], $this->seen($before));

        $this->assertContains('/settings/system', collect($after['steps'])->where('do', 'visit')->pluck('path')->all());
        $this->assertSame([', Jane', '1.1.0', 'Up to date'], $this->seen($after));
        $this->assertSame('.eos-tag-warning', collect($after['steps'])->firstWhere('do', 'gone')['target']);

        // The same password both times: it is the one set for this run.
        $this->assertSame(
            collect($before['steps'])->firstWhere('target', '#password')['value'],
            collect($after['steps'])->firstWhere('target', '#password')['value'],
        );
    }

    public function test_a_cockpit_still_reading_dashcoretech_is_refused_before_anybody_signs_in(): void
    {
        $this->cockpitConfig['release.repository'] = 'dashcoretech/ceo.dashcore.com';
        $this->fakeProcesses();

        [$exit, $output] = $this->journey();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('release.repository is "dashcoretech\\/ceo.dashcore.com"', $output);
        $this->assertStringContainsString('Environment::releases', $output);
        $this->assertStringContainsString('fleets put it back on v1.1.0.', $output);

        // Not left on the older release for nothing.
        $this->assertSame([['acme-care', 'ceo', 'v1.0.0'], ['acme-care', 'ceo', 'v1.1.0']], $this->pushes);
        $this->assertSame([], $this->walks);
        Process::assertDidntRun(fn (PendingProcess $p) => in_array('app:make-admin', (array) $p->command, true));
    }

    public function test_a_cockpit_with_no_token_for_templates_is_refused_with_the_key_to_file(): void
    {
        $this->cockpitConfig['starts_with:release.token'] = false;
        $this->fakeProcesses();

        [$exit, $output] = $this->journey();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Templates is private and it holds no read-only token', $output);
        $this->assertStringContainsString('fleets.dashcore.com__TEMPLATES_READ_TOKEN__production', $output);
        $this->assertStringContainsString('https://keys.dashcore.com.test/keys/new?app=fleets.dashcore.com&environment=production&variable=TEMPLATES_READ_TOKEN&provider=GitHub', $output);
        $this->assertStringNotContainsString('__RELEASE_TOKEN__', $output);

        $this->assertSame([], $this->walks);
    }

    public function test_a_push_fleets_cannot_prove_stops_the_journey(): void
    {
        $this->pushSucceeds = false;
        $this->fakeProcesses();

        [$exit, $output] = $this->journey();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('fleets could not put acme-care\'s ceo on v1.0.0', $output);

        $this->assertSame([], $this->walks);
    }

    public function test_an_upgrade_the_owner_does_not_see_arrive_fails_the_journey(): void
    {
        $this->failingStep = 'Up to date';
        $this->fakeProcesses();

        [$exit, $output] = $this->journey();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Upgrade failed after', $output);

        $this->assertCount(2, $this->walks);
        $this->assertCount(2, $this->pushes);
    }

    public function test_only_a_practice_fleets_customer_is_upgraded_here(): void
    {
        config(['simulation.fleets.practice.install' => 'dashcore']);
        $this->fakeProcesses();

        [$exit, $output] = $this->journey();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('is not a practice fleet', $output);

        $this->assertSame([], $this->pushes);
    }
}
