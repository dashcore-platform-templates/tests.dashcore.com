<?php

namespace Dashcore\Testkit;

use Dashcore\Testkit\Checks\LoadingFeedback;
use Dashcore\Testkit\Checks\RouteGuards;
use Dashcore\Testkit\Checks\SourceCalls;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;

/**
 * The fleet baseline, as assertions a test calls. Each failure message says
 * what to do — fix it, or record a deliberate exception in config/testkit.php.
 */
final class Testkit
{
    public static function assertRoutesAreGuarded(): void
    {
        $unguarded = app(RouteGuards::class)->unguarded();

        Assert::assertSame([], $unguarded, "These routes have no authentication guard and are not declared public:\n  "
            .implode("\n  ", $unguarded)
            ."\nAdd auth (or another guard) to them, or list them in testkit.routes.public if anyone may reach them.");
    }

    public static function assertTestsRunOn(?string $driver = null): void
    {
        $driver ??= config('testkit.database.driver');

        if ($driver === null) {
            Assert::assertTrue(true);

            return;
        }

        Assert::assertSame($driver, DB::connection()->getDriverName(), "The suite runs on ".DB::connection()->getDriverName()." but production runs {$driver}: point phpunit.xml at a {$driver} test database (forced), so queries are tested on the engine they run on.");
    }

    /**
     * Every migration rolls back and runs again cleanly, leaving the schema as
     * it was. Uses the test database; it is migrated again afterwards.
     */
    public static function assertMigrationsRoundTrip(): void
    {
        Assert::assertTrue(app()->environment('testing'), 'Refusing to drop and rebuild the database outside the testing environment.');

        Artisan::call('migrate:fresh', ['--force' => true]);
        $before = self::schema();

        $rollback = Artisan::call('migrate:reset', ['--force' => true]);
        $output = Artisan::output();
        Assert::assertSame(0, $rollback, "Rolling every migration back failed:\n".$output);
        Assert::assertSame([], array_values(array_diff(self::tables(), ['migrations'])), 'Tables are left after rolling every migration back: a down() method does not undo its up().');

        Assert::assertSame(0, Artisan::call('migrate', ['--force' => true]), "Migrating again after a full rollback failed:\n".Artisan::output());
        Assert::assertSame($before, self::schema(), 'The schema differs after rolling back and migrating again: a down() method does not undo its up() exactly.');
    }

    public static function assertNoDebugCalls(): void
    {
        $calls = app(SourceCalls::class);
        $found = $calls->except(
            $calls->find((array) config('testkit.debug_calls.functions'), ['app', 'routes', 'database', 'config']),
            (array) config('testkit.debug_calls.allow'),
        );

        Assert::assertSame([], $found, "Debugging calls left in the app:\n  ".implode("\n  ", $found)."\nRemove them.");
    }

    public static function assertNoEnvOutsideConfig(): void
    {
        $calls = app(SourceCalls::class);
        $found = $calls->except($calls->find(['env'], ['app', 'routes', 'database', 'resources']), (array) config('testkit.env_calls.allow'));

        Assert::assertSame([], $found, "env() called outside config/ — it returns null once config is cached:\n  ".implode("\n  ", $found)."\nRead the value through config() instead.");
    }

    public static function assertLivewireActionsGiveFeedback(): void
    {
        $missing = array_values(array_diff(app(LoadingFeedback::class)->missing(), (array) config('testkit.loading.allow')));

        Assert::assertSame([], $missing, "Livewire actions with no loading feedback (DashCore rule: no dead space):\n  ".implode("\n  ", $missing)."\nAdd wire:loading aimed at the action (wire:target), or wire:loading.attr=\"disabled\" on the element.");
    }

    /**
     * Table names without their schema prefix (`public.users`, `main.users`).
     *
     * @return list<string>
     */
    private static function tables(): array
    {
        return array_values(array_map(
            fn (string $t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t,
            Schema::getTableListing(),
        ));
    }

    /**
     * A comparable picture of the schema: every table's columns and indexes.
     *
     * @return array<string, array{columns: list<string>, indexes: list<string>}>
     */
    private static function schema(): array
    {
        $schema = [];

        foreach (self::tables() as $table) {
            if ($table === 'migrations') {
                continue;
            }

            $schema[$table] = [
                'columns' => collect(Schema::getColumns($table))->map(fn (array $c) => $c['name'].':'.$c['type'].($c['nullable'] ? '?' : ''))->sort()->values()->all(),
                'indexes' => collect(Schema::getIndexes($table))->map(fn (array $i) => implode(',', $i['columns']).($i['unique'] ? ':unique' : ''))->sort()->values()->all(),
            ];
        }

        ksort($schema);

        return $schema;
    }
}
