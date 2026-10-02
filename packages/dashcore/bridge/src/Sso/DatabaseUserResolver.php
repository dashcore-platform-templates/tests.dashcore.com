<?php

namespace Dashcore\Bridge\Sso;

use Dashcore\Bridge\Contracts\ResolvesSsoUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The default: match on the subject, fall back to the email address once.
 *
 * The subject is the sign-in server's permanent handle for a person. The
 * fallback exists for the only moment it is safe — an app whose people
 * predate single sign-in, matching someone the first time and writing the
 * subject down so it never has to match on an email again. It is taken only
 * when the address is one the sign-in server says it verified, because an
 * unverified address is a claim, not an identity.
 *
 * Provisioning is off unless the install turns it on. An app where accounts
 * carry roles, seats or a pay rate should not grow one because somebody who
 * could sign in elsewhere opened its URL.
 */
final class DatabaseUserResolver implements ResolvesSsoUser
{
    public function resolve(SsoIdentity $identity): ?Authenticatable
    {
        /** @var class-string<Model&Authenticatable> $model */
        $model = config('bridge.sso.user_model') ?: config('auth.providers.users.model');
        $column = (string) config('bridge.sso.subject_column', 'sso_subject');
        $hasColumn = Schema::hasColumn((new $model)->getTable(), $column);

        $user = $hasColumn
            ? $model::query()->where($column, $identity->subject)->first()
            : null;

        if ($user === null && $identity->email !== null && $identity->emailVerified) {
            $user = $model::query()->where('email', $identity->email)->first();
        }

        if ($user === null) {
            return config('bridge.sso.provision', false) ? $this->provision($model, $identity, $column, $hasColumn) : null;
        }

        // Written down on first sight, so this person is never matched by
        // their email again — and an address they change later still lands
        // on the same account.
        if ($hasColumn && $user->{$column} !== $identity->subject) {
            $user->forceFill([$column => $identity->subject])->save();
        }

        return $user;
    }

    /**
     * @param  class-string<Model&Authenticatable>  $model
     */
    private function provision(string $model, SsoIdentity $identity, string $column, bool $hasColumn): ?Authenticatable
    {
        if ($identity->email === null || ! $identity->emailVerified) {
            return null;
        }

        $attributes = [
            'name' => $identity->name ?? $identity->email,
            'email' => $identity->email,
            'email_verified_at' => now(),
        ];

        if ($hasColumn) {
            $attributes[$column] = $identity->subject;
        }

        // Most apps' password column is NOT NULL, and this person has no
        // password here — they have a passkey at the sign-in server. A random
        // hash nobody holds the input to satisfies the column while leaving
        // the local password form no way in; someone who wants one resets it.
        $table = (new $model)->getTable();

        if (Schema::hasColumn($table, 'password')) {
            $attributes['password'] = Hash::make(Str::random(64));
        }

        return $model::query()->forceCreate($attributes);
    }
}
