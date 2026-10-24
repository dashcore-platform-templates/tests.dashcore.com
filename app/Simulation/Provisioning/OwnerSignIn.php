<?php

namespace App\Simulation\Provisioning;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * How a new client's owner got in on their first sign-in, kept for the
 * stages after it: which door the build gave them — login's invitation, or
 * ceo's own password link when the build fell back — and the password they
 * chose there. A practice owner's, made up for one run, held encrypted for
 * a day and never shown.
 */
class OwnerSignIn
{
    public const LOGIN = 'login';

    public const CEO = 'ceo';

    public function keep(string $install, string $door, string $email, string $password): void
    {
        Cache::put($this->key($install), Crypt::encryptString((string) json_encode(compact('door', 'email', 'password'))), now()->addDay());
    }

    /**
     * @return array{door: string, email: string, password: string}|null
     */
    public function of(string $install): ?array
    {
        $kept = Cache::get($this->key($install));

        if (! is_string($kept)) {
            return null;
        }

        $decoded = json_decode(Crypt::decryptString($kept), true);

        return is_array($decoded) && isset($decoded['door'], $decoded['email'], $decoded['password']) ? $decoded : null;
    }

    private function key(string $install): string
    {
        return "simulation.owner-sign-in.{$install}";
    }
}
