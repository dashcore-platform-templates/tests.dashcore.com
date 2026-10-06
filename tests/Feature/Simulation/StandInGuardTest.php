<?php

namespace Tests\Feature\Simulation;

use App\Simulation\StandInGuard;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StandInGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/pg-guard-'.bin2hex(random_bytes(4));

        foreach (['sales.travischapman.practice', 'sales.fakecompany.com', 'sales.dashcore.com'] as $checkout) {
            File::ensureDirectoryExists("{$this->root}/{$checkout}");
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: ?string, 1: bool}>
     */
    public static function checkouts(): array
    {
        return [
            'a fake company app' => ['/Users/x/Herd/sales.fakecompany.com', true],
            'an app of a practice fleet' => ['/Users/x/Herd/sales.travischapman.practice', true],
            'a practice fleet\'s website, at its apex' => ['/Users/x/Herd/travischapman.practice/', true],
            'a real app' => ['/Users/x/Herd/sales.dashcore.com', false],
            'a real app whose parent folder says practice' => ['/Users/x/practice.stuff/sales.dashcore.com', false],
            'practice without the dot' => ['/Users/x/Herd/practice-notes', false],
            'nothing' => [null, false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('checkouts')]
    public function test_only_fake_company_and_practice_checkouts_are_stand_ins(?string $checkout, bool $standIn): void
    {
        $this->assertSame($standIn, StandInGuard::isStandIn($checkout));
    }

    public function test_it_refuses_a_real_or_missing_checkout(): void
    {
        $guard = app(StandInGuard::class);

        $this->assertNull($guard->checkoutRefusal("{$this->root}/sales.travischapman.practice"));
        $this->assertNull($guard->checkoutRefusal("{$this->root}/sales.fakecompany.com"));
        $this->assertStringContainsString('is not a stand-in app', (string) $guard->checkoutRefusal("{$this->root}/sales.dashcore.com"));
        $this->assertStringContainsString('no checkout', (string) $guard->checkoutRefusal("{$this->root}/gone.travischapman.practice"));
    }

    public function test_an_app_whose_mail_goes_to_its_log_is_allowed(): void
    {
        Process::fake(['*' => Process::result("booting\n@@simulation-config@@".json_encode(['mail.default' => 'log'])."\n")]);

        $this->assertNull(app(StandInGuard::class)->mailRefusal("{$this->root}/sales.travischapman.practice"));
    }

    public function test_an_app_that_would_send_real_mail_is_refused(): void
    {
        Process::fake(['*' => Process::result('@@simulation-config@@'.json_encode(['mail.default' => 'resend'])."\n")]);

        $refusal = app(StandInGuard::class)->mailRefusal("{$this->root}/sales.travischapman.practice");

        $this->assertSame('sales.travischapman.practice would send real email: mail.default is "resend", and must be "log".', $refusal);
    }

    public function test_an_app_must_also_hold_whatever_else_keeps_its_mail_in(): void
    {
        // The website mails through Resend's API directly: log as its mailer
        // is not enough if it runs as production.
        Process::fake(['*' => Process::result('@@simulation-config@@'.json_encode(['mail.default' => 'log', 'app.env' => 'production', 'mail.local_really_send' => false])."\n")]);

        $refusal = app(StandInGuard::class)->mailRefusal("{$this->root}/sales.travischapman.practice", ['app.env' => 'local', 'mail.local_really_send' => false]);

        $this->assertStringContainsString('app.env is "production", and must be "local"', (string) $refusal);
    }

    public function test_a_value_of_the_wrong_kind_is_not_close_enough(): void
    {
        Process::fake(['*' => Process::result('@@simulation-config@@'.json_encode(['mail.default' => 'log', 'mail.local_really_send' => 'false'])."\n")]);

        $this->assertNotNull(app(StandInGuard::class)->mailRefusal("{$this->root}/sales.travischapman.practice", ['mail.local_really_send' => false]));
    }

    public function test_settings_that_cannot_be_read_are_a_refusal(): void
    {
        $guard = app(StandInGuard::class);

        Process::fake(['*' => Process::result('', 'PHP Fatal error', 255)]);
        $this->assertStringContainsString('could not read the mail settings', (string) $guard->mailRefusal("{$this->root}/sales.travischapman.practice"));

        Process::fake(['*' => Process::result("something else entirely\n")]);
        $this->assertStringContainsString('did not say', (string) $guard->mailRefusal("{$this->root}/sales.travischapman.practice"));
    }

    public function test_the_settings_are_read_in_the_apps_checkout_with_nothing_inherited(): void
    {
        Process::fake(['*' => Process::result('@@simulation-config@@'.json_encode(['mail.default' => 'log'])."\n")]);
        putenv('MAIL_MAILER=log');
        putenv('LOCAL_MAIL_REALLY_SEND=false');

        try {
            app(StandInGuard::class)->mailRefusal("{$this->root}/sales.travischapman.practice");
        } finally {
            putenv('MAIL_MAILER');
            putenv('LOCAL_MAIL_REALLY_SEND');
        }

        // A value exported into this shell would beat the app's own .env and
        // could make a resend app read as log.
        Process::assertRan(fn (PendingProcess $p) => $p->path === "{$this->root}/sales.travischapman.practice"
            && $p->command[0] === 'php' && $p->command[1] === '-r'
            && str_contains($p->command[2], "'mail.default'")
            && ($p->environment['MAIL_MAILER'] ?? null) === false
            && ($p->environment['LOCAL_MAIL_REALLY_SEND'] ?? null) === false
            && ($p->environment['PATH'] ?? null) === getenv('PATH'));
    }

    public function test_an_app_on_stripe_test_keys_is_allowed(): void
    {
        Process::fake(['*' => Process::result('@@simulation-config@@'.json_encode(['starts_with:services.stripe.secret' => true])."\n")]);

        $this->assertNull(app(StandInGuard::class)->testModeRefusal("{$this->root}/sales.travischapman.practice", ['services.stripe.secret' => 'sk_test_']));
    }

    public function test_an_app_on_live_or_missing_keys_is_refused_without_the_key_being_read(): void
    {
        Process::fake(['*' => Process::result('@@simulation-config@@'.json_encode(['starts_with:services.stripe.secret' => false])."\n")]);

        $refusal = app(StandInGuard::class)->testModeRefusal("{$this->root}/sales.travischapman.practice", ['services.stripe.secret' => 'sk_test_']);

        $this->assertSame('sales.travischapman.practice is not in test mode: services.stripe.secret is not a sk_test_ key.', $refusal);

        // The app is asked whether its key starts as a test key does — the
        // question goes in, a yes or no comes out, and the key stays put.
        Process::assertRan(fn (PendingProcess $p) => $p->command[1] === '-r'
            && str_contains($p->command[2], "'starts_with'")
            && str_contains($p->command[2], "'sk_test_'")
            && str_contains($p->command[2], 'str_starts_with($value, $argument)'));
    }

    public function test_an_answer_that_is_not_a_plain_yes_is_a_refusal(): void
    {
        $guard = app(StandInGuard::class);

        // A key brought back instead of an answer is not a yes.
        Process::fake(['*' => Process::result('@@simulation-config@@'.json_encode(['starts_with:services.stripe.secret' => 'sk_test_abc'])."\n")]);
        $this->assertNotNull($guard->testModeRefusal("{$this->root}/sales.travischapman.practice", ['services.stripe.secret' => 'sk_test_']));

        Process::fake(['*' => Process::result('', 'PHP Fatal error', 255)]);
        $this->assertStringContainsString('could not read the payment settings', (string) $guard->testModeRefusal("{$this->root}/sales.travischapman.practice", ['services.stripe.secret' => 'sk_test_']));
    }

    public function test_a_secret_is_compared_by_fingerprint_and_never_sent(): void
    {
        $secret = 'whsec_'.bin2hex(random_bytes(16));
        Process::fake(['*' => Process::result('@@simulation-config@@'.json_encode(['sha256:services.stripe.webhook_secret' => hash('sha256', $secret)])."\n")]);
        $guard = app(StandInGuard::class);

        $this->assertTrue($guard->holdsSecret("{$this->root}/sales.travischapman.practice", 'services.stripe.webhook_secret', $secret));
        $this->assertFalse($guard->holdsSecret("{$this->root}/sales.travischapman.practice", 'services.stripe.webhook_secret', 'whsec_somebody_else'));
        $this->assertFalse($guard->holdsSecret("{$this->root}/sales.travischapman.practice", 'services.stripe.webhook_secret', ''));

        Process::assertDidntRun(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), $secret)
            || in_array($secret, array_filter((array) $p->environment, 'is_string'), true));
    }

    public function test_an_app_that_holds_no_secret_or_cannot_say_does_not_hold_it(): void
    {
        $guard = app(StandInGuard::class);

        Process::fake(['*' => Process::result('@@simulation-config@@'.json_encode(['sha256:services.stripe.webhook_secret' => null])."\n")]);
        $this->assertFalse($guard->holdsSecret("{$this->root}/sales.travischapman.practice", 'services.stripe.webhook_secret', 'whsec_x'));

        Process::fake(['*' => Process::result('', 'PHP Fatal error', 255)]);
        $this->assertNull($guard->holdsSecret("{$this->root}/sales.travischapman.practice", 'services.stripe.webhook_secret', 'whsec_x'));
    }
}
