<?php

namespace Tests\Feature\Simulation;

use App\Simulation\Simulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PrepareTargetTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/pg-fleet-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->root.'/finance.fakecompany.com');
        File::ensureDirectoryExists($this->root.'/finance.dashcore.com');

        config([
            'simulation.targets.finance.checkout' => $this->root.'/finance.fakecompany.com',
            // A database nobody can reach: the app has no people yet.
            'simulation.targets.finance.database' => 'no_such_database_here',
            'simulation.targets.finance.connection' => [],
            'simulation.targets.finance.prepare' => ['migrate --force', 'db:seed --force'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_runs_the_apps_own_migrations_and_seeders_and_makes_its_personas_in_its_checkout(): void
    {
        Process::fake();

        $this->artisan('tests:prepare-target', ['target' => 'finance']);

        $checkout = $this->root.'/finance.fakecompany.com';
        Process::assertRan(fn (PendingProcess $p) => $p->path === $checkout && $p->command === ['php', 'artisan', 'migrate', '--force', '--no-interaction']);
        Process::assertRan(fn (PendingProcess $p) => $p->path === $checkout && $p->command === ['php', 'artisan', 'db:seed', '--force', '--no-interaction']);
        Process::assertRan(fn (PendingProcess $p) => $p->path === $checkout
            && $p->command[2] === 'tinker'
            && str_contains($p->command[4], "'fiona.grant@example.com'")
            && str_contains($p->command[4], "'admin'"));
    }

    public function test_the_apps_commands_never_see_this_apps_settings(): void
    {
        Process::fake();

        $this->artisan('tests:prepare-target', ['target' => 'finance']);

        // Laravel never overrides a variable that is already set: had these
        // been inherited, the target's migrate would run against this app's
        // own database.
        Process::assertRan(fn (PendingProcess $p) => $p->command[2] === 'migrate'
            && ($p->environment['DB_DATABASE'] ?? null) === false
            && ($p->environment['DB_CONNECTION'] ?? null) === false
            && ($p->environment['APP_KEY'] ?? null) === false);
        Process::assertDidntRun(fn (PendingProcess $p) => ! array_key_exists('DB_DATABASE', $p->environment));
    }

    public function test_it_only_prepares_fake_company_apps(): void
    {
        Process::fake();
        config(['simulation.targets.finance.checkout' => $this->root.'/finance.dashcore.com']);

        $this->artisan('tests:prepare-target', ['target' => 'finance'])
            ->expectsOutputToContain('is not a fake company app')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_an_unknown_target_or_missing_checkout_is_refused(): void
    {
        Process::fake();

        $this->artisan('tests:prepare-target', ['target' => 'nope'])->assertFailed();

        config(['simulation.targets.finance.checkout' => $this->root.'/gone.fakecompany.com']);
        $this->artisan('tests:prepare-target', ['target' => 'finance'])->assertFailed();

        Process::assertNothingRan();
    }

    public function test_the_target_environment_takes_out_every_setting_of_this_app(): void
    {
        $environment = app(Simulation::class)->targetEnvironment();

        $this->assertSame([false], array_values(array_unique($environment)));
        $this->assertArrayHasKey('DB_DATABASE', $environment);
        $this->assertArrayHasKey('APP_KEY', $environment);
        $this->assertArrayNotHasKey('PATH', $environment);
        $this->assertArrayNotHasKey('HOME', $environment);
    }
}
