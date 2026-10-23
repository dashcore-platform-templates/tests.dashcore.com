<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Take the admins from a client brief.
 *
 * fleets holds what a client told Dashcore for their launch, and hands it to
 * each app of their new fleet before the owner first signs in. login is the
 * only way in and tests never makes an account on sign-in (BRIDGE_SSO_PROVISION
 * stays off), so the people who run the fleet for the client get their tests
 * accounts here: the owner becomes the Owner, every other admin an Admin.
 *
 * Nobody is emailed, and the password is random and never printed, because
 * signing in goes through login. email_verified_at is set with forceFill(), so
 * MustVerifyEmail never sends a verification mail.
 *
 * Safe to run again: an existing account keeps its password, and roles are
 * only ever raised — an Owner is never made an Admin.
 */
class ImportClientBrief extends Command
{
    public const FORMAT = 'dashcore.client-brief/1';

    protected $signature = 'brief:import
        {--file= : A client brief (JSON)}
        {--base64= : The same brief, base64-encoded, for a host with nowhere to put a file}';

    protected $description = 'Take the admins from a client brief that fleets delivers';

    public function handle(): int
    {
        $json = match (true) {
            filled($this->option('file')) => is_readable((string) $this->option('file')) ? file_get_contents((string) $this->option('file')) : null,
            filled($this->option('base64')) => base64_decode((string) $this->option('base64'), true),
            default => null,
        };
        $brief = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($brief)) {
            $this->error('Give a readable client brief with --file or --base64.');

            return self::FAILURE;
        }

        if (($brief['format'] ?? null) !== self::FORMAT) {
            $this->error('Not a client brief (expected format '.self::FORMAT.').');

            return self::FAILURE;
        }

        $admins = $this->admins($brief['admins'] ?? null);

        if ($admins->isEmpty()) {
            $this->line('Nothing for tests in this brief.');

            return self::SUCCESS;
        }

        $created = [];
        $raised = [];
        $already = [];

        DB::transaction(function () use ($admins, &$created, &$raised, &$already) {
            foreach ($admins->values() as $position => $admin) {
                // Sign-in matches the lowercased address, so that is what is stored.
                $email = Str::lower(trim($admin['email']));
                $role = $position === 0 ? UserRole::Owner : UserRole::Admin;
                $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

                if ($user === null) {
                    User::query()->create([
                        'name' => filled($admin['name'] ?? null) ? trim($admin['name']) : Str::of($email)->before('@')->replace(['.', '+'], ' ')->headline()->value(),
                        'email' => $email,
                        'password' => Str::password(64),
                        'role' => $role,
                    ])->forceFill(['email_verified_at' => now()])->save();
                    $created[] = "{$email} ({$role->value})";

                    continue;
                }

                if ($user->role->isAtLeast($role)) {
                    $already[] = "{$email} ({$user->role->value})";

                    continue;
                }

                $user->forceFill(['role' => $role])->save();
                $raised[] = "{$email} ({$role->value})";
            }
        });

        $this->line(collect([
            $created !== [] ? 'Added: '.implode(', ', $created).'.' : null,
            $raised !== [] ? 'Raised: '.implode(', ', $raised).'.' : null,
            $already !== [] ? 'Already in: '.implode(', ', $already).'.' : null,
        ])->filter()->implode(' '));

        return self::SUCCESS;
    }

    /**
     * The brief's admins with a valid address, the owner first. A brief from
     * before fleets marked the owner already lists them first, so then the
     * first admin is taken as the owner.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function admins(mixed $admins): Collection
    {
        return collect(is_array($admins) ? $admins : [])
            ->filter(fn ($admin) => is_array($admin) && is_string($admin['email'] ?? null) && filter_var(trim($admin['email']), FILTER_VALIDATE_EMAIL))
            ->sortBy(fn (array $admin) => ($admin['owner'] ?? false) === true ? 0 : 1)
            ->values();
    }
}
