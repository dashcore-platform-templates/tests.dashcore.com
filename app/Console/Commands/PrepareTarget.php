<?php

namespace App\Console\Commands;

use App\Simulation\Simulation;
use Illuminate\Console\Command;
use Illuminate\Console\View\TaskResult;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Gets a fake app ready for the Proving Ground: runs its own migrations and
 * seeders in its checkout, so the personas exist and there is a business to
 * work in. Only ever a *.fakecompany.com copy, and only one nobody has set
 * up yet — it never reseeds an app with people in it.
 */
class PrepareTarget extends Command
{
    protected $signature = 'tests:prepare-target {target : The simulation target to set up}';

    protected $description = 'Migrate and seed a fake app so the Proving Ground can work in it';

    public function handle(Simulation $simulation): int
    {
        $target = (string) $this->argument('target');
        $config = config("simulation.targets.{$target}");

        if ($config === null) {
            $this->components->error("No simulation target \"{$target}\".");

            return self::FAILURE;
        }

        $checkout = $config['checkout'] ?? null;

        if (! is_string($checkout) || ! is_dir($checkout)) {
            $this->components->error("No checkout of {$target} at ".var_export($checkout, true).'.');

            return self::FAILURE;
        }

        if (! str_contains(basename($checkout), 'fakecompany')) {
            $this->components->error("{$checkout} is not a fake company app; only *.fakecompany.com checkouts are prepared.");

            return self::FAILURE;
        }

        if ($this->hasPeople($simulation, $target, $config)) {
            $this->components->info("{$config['name']} already has people in it; nothing to do.");

            return self::SUCCESS;
        }

        $commands = $config['prepare'] ?? ['migrate --force', 'db:seed --force'];

        foreach ($commands as $command) {
            $this->components->task("{$config['name']}: php artisan {$command}", function () use ($checkout, $command, $simulation) {
                $result = Process::path($checkout)->env($simulation->targetEnvironment())->timeout(600)->run(['php', 'artisan', ...explode(' ', $command), '--no-interaction']);

                if (! $result->successful()) {
                    $this->newLine();
                    $this->line('<fg=red>'.trim($result->errorOutput() ?: $result->output()).'</>');
                }

                return ($result->successful() ? TaskResult::Success : TaskResult::Failure)->value;
            });
        }

        $this->createPersonas($checkout, $config, $simulation);

        return $this->hasPeople($simulation, $target, $config) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Personas the app's seeders do not make — those with an app_role — are
     * made with the app's own User model, so its casts and hashing apply.
     *
     * @param  array<string, mixed>  $config
     */
    private function createPersonas(string $checkout, array $config, Simulation $simulation): void
    {
        foreach ($config['personas'] ?? [] as $persona) {
            if (! isset($persona['app_role'])) {
                continue;
            }

            // forceFill: apps differ in what they let be mass-assigned, and
            // a role is usually guarded. Only columns the app has are set.
            $code = sprintf(
                '$u = \\App\\Models\\User::query()->firstOrNew(["email" => %1$s]); $cols = \\Illuminate\\Support\\Facades\\Schema::getColumnListing($u->getTable()); $u->forceFill(array_intersect_key(["name" => %2$s, "password" => \\Illuminate\\Support\\Facades\\Hash::make(%3$s), "role" => %4$s, "email_verified_at" => now()], array_flip($cols)))->save(); if (method_exists($u, "syncRoles")) { $u->syncRoles([%4$s]); }',
                var_export($persona['email'], true),
                var_export($persona['name'], true),
                var_export((string) $config['password'], true),
                var_export($persona['app_role'], true),
            );

            $this->components->task("{$config['name']}: {$persona['name']} as {$persona['app_role']}", fn () => (Process::path($checkout)->env($simulation->targetEnvironment())->timeout(120)->run(['php', 'artisan', 'tinker', '--execute', $code])->successful()
                ? TaskResult::Success : TaskResult::Failure)->value);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function hasPeople(Simulation $simulation, string $target, array $config): bool
    {
        if (empty($config['database'])) {
            return false;
        }

        try {
            $db = $simulation->targetDatabase($target, $config['database']);

            return $db->getSchemaBuilder()->hasTable('users') && $db->table('users')->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
