<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class MakeAdmin extends Command
{
    protected $signature = 'app:make-admin
        {email? : Address to promote; defaults to ADMIN_EMAIL}
        {--create : Create the account when nobody has that address}
        {--name= : Name for a created account; defaults to ADMIN_NAME, then the address}
        {--password= : Password to set; generated and printed once when omitted}
        {--keep-password : Leave an existing account\'s password alone}';

    protected $description = 'Grant a user platform owner access, creating the account first with --create';

    public function handle(): int
    {
        $email = mb_strtolower(trim(
            (string) ($this->argument('email') ?? config('app.admin.email'))
        ));

        if ($email === '') {
            $this->components->error('No address given. Pass one as an argument or set ADMIN_EMAIL.');

            return self::FAILURE;
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        $generated = null;

        if ($user) {
            $generated = $this->rotatePassword($user);

            if ($generated === false) {
                return self::FAILURE;
            }
        } else {
            if (! $this->option('create')) {
                $this->components->error("No account uses {$email}.");
                $this->components->info('Re-run with --create to make one.');

                return self::FAILURE;
            }

            [$user, $generated] = $this->createAccount($email);

            if (! $user) {
                return self::FAILURE;
            }
        }

        $user->forceFill([
            'role' => UserRole::Owner,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->components->twoColumnDetail('Admin', 'yes (owner)');

        if (is_string($generated)) {
            $this->components->twoColumnDetail('Password', $generated);
            $this->components->warn('Shown once — store it now, then change it after signing in.');
        }

        return self::SUCCESS;
    }

    /**
     * @return string|false|null Password to show (string), kept (null), or invalid (false).
     */
    private function rotatePassword(User $user): string|false|null
    {
        if ($this->option('keep-password')) {
            return null;
        }

        $password = $this->option('password') ?? Str::password(20);
        $generated = $this->option('password') ? null : $password;

        if (! $this->validatePassword($password)) {
            return false;
        }

        $user->forceFill(['password' => $password])->save();

        return $generated ?? $password;
    }

    /**
     * @return array{0: User|null, 1: string|null}
     */
    private function createAccount(string $email): array
    {
        $name = trim((string) ($this->option('name') ?? config('app.admin.name')));

        if ($name === '') {
            $name = Str::of($email)->before('@')->headline()->value();
        }

        $password = $this->option('password') ?? Str::password(20);
        $generated = $this->option('password') ? null : $password;

        if (! $this->validatePassword($password)) {
            return [null, null];
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $this->components->twoColumnDetail('Created', $email);

        return [$user, $generated ?? $password];
    }

    private function validatePassword(string $password): bool
    {
        try {
            validator(['password' => $password], [
                'password' => ['required', 'string', Password::defaults()],
            ])->validate();
        } catch (ValidationException $e) {
            $this->components->error('Password does not meet the requirements.');

            foreach ($e->errors()['password'] ?? [] as $message) {
                $this->components->bulletList([$message]);
            }

            return false;
        }

        return true;
    }
}
