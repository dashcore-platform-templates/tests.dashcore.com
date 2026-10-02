<?php

namespace Tests\Feature;

use Dashcore\Testkit\Testkit;
use Tests\TestCase;

/**
 * The DashCore fleet baseline (dashcore/testkit). Each check is a ratchet:
 * exceptions that existed when it was installed are listed in
 * config/testkit.php; anything new fails here.
 */
class FleetBaselineTest extends TestCase
{
    public function test_every_app_route_has_an_authentication_guard_or_is_declared_public(): void
    {
        Testkit::assertRoutesAreGuarded();
    }

    public function test_the_suite_runs_on_the_production_database_engine(): void
    {
        Testkit::assertTestsRunOn();
    }

    public function test_every_migration_rolls_back_and_runs_again_cleanly(): void
    {
        Testkit::assertMigrationsRoundTrip();
    }

    public function test_no_debugging_calls_are_left_in_the_app(): void
    {
        Testkit::assertNoDebugCalls();
    }

    public function test_env_is_only_read_in_config(): void
    {
        Testkit::assertNoEnvOutsideConfig();
    }

    public function test_every_livewire_action_gives_loading_feedback(): void
    {
        Testkit::assertLivewireActionsGiveFeedback();
    }
}
