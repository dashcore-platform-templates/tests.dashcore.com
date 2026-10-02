<?php

namespace Dashcore\Bridge\Sso;

use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Refuses the app's own password sign-in when sso is required, for everyone
 * but the break-glass accounts.
 *
 * Two layers, because apps sign people in two ways:
 *
 * - Attempting: Breeze (Blade or Livewire) and Fortify's default call
 *   Auth::attempt(). Refusing here, before the password is checked, also
 *   means the answer never says whether the password was right.
 * - Login: an app with its own authenticator (Fortify's authenticateUsing)
 *   calls Auth::login() directly and never fires Attempting. A login on a
 *   POST to the app's sign-in route is refused after the fact: the session is
 *   logged out again before the response is written.
 *
 * A login anywhere else — the sso callback, a remember-me cookie being
 * honoured — is not a password sign-in and is left alone.
 */
final class PasswordSignIn
{
    public const MESSAGE = 'Sign in through the fleet\'s sign-in instead.';

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Attempting::class, [$this, 'attempting']);
        $events->listen(Login::class, [$this, 'login']);
    }

    public function attempting(Attempting $event): void
    {
        $field = self::field();
        $identifier = $event->credentials[$field] ?? null;

        if (! SsoMode::allowsPasswordFor(is_string($identifier) ? $identifier : null)) {
            throw ValidationException::withMessages([$field => __(self::MESSAGE)]);
        }
    }

    public function login(Login $event): void
    {
        if (! SsoMode::isRequired() || ! self::isPasswordSignInRequest(request())) {
            return;
        }

        $identifier = $event->user->{self::field()} ?? null;

        if (SsoMode::allowsPasswordFor(is_string($identifier) ? $identifier : null)) {
            return;
        }

        Auth::guard($event->guard)->logout();

        throw ValidationException::withMessages([self::field() => __(self::MESSAGE)]);
    }

    public static function isPasswordSignInRequest(Request $request): bool
    {
        return in_array($request->route()?->getName(), (array) config('bridge.sso.login_routes', ['login', 'login.store']), true);
    }

    private static function field(): string
    {
        return (string) config('bridge.sso.username', 'email');
    }
}
