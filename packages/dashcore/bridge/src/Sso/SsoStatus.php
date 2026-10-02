<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Sso;

use Dashcore\Bridge\BridgeVersion;
use Dashcore\Bridge\Contracts\ResolvesSsoUser;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Where this app stands on signing people in through the fleet, said by the
 * app itself — so whoever rolls sso out reads the running truth rather than
 * inferring it from a checkout, a tag or an environment variable.
 *
 * Nothing secret: the break-glass accounts are counted, never named, and the
 * resolver is named by class only.
 */
final class SsoStatus
{
    /**
     * @return array{
     *     bridge: string,
     *     mode: string,
     *     configured: string,
     *     break_glass: int,
     *     subject_column: ?bool,
     *     resolver: string,
     *     app_resolver: bool,
     *     provision: bool,
     *     login_route: ?string,
     * }
     */
    public static function report(): array
    {
        $resolver = self::resolver();
        $loginRoute = ((array) config('bridge.sso.login_routes', ['login', 'login.store']))[0] ?? null;

        return [
            'bridge' => BridgeVersion::version(),
            // What is enforced. `configured` differs only when required is
            // served as optional for want of a break-glass account.
            'mode' => SsoMode::current(),
            'configured' => SsoMode::configured(),
            'break_glass' => count(SsoMode::breakGlass()),
            'subject_column' => self::subjectColumn(),
            'resolver' => $resolver,
            // The bridge's default matches anyone with an account; an app with
            // its own rule (deactivated, admins only, the owner) binds its own.
            'app_resolver' => $resolver !== DatabaseUserResolver::class,
            'provision' => (bool) config('bridge.sso.provision', false),
            // Where a failed sign-in lands, when the app has that route.
            'login_route' => is_string($loginRoute) && Route::has($loginRoute) ? $loginRoute : null,
        ];
    }

    /** Null when it cannot be told: no users model, or no database to ask. */
    private static function subjectColumn(): ?bool
    {
        $model = config('bridge.sso.user_model') ?: config('auth.providers.users.model');

        if (! is_string($model) || ! class_exists($model)) {
            return null;
        }

        try {
            return Schema::hasColumn((new $model)->getTable(), (string) config('bridge.sso.subject_column', 'sso_subject'));
        } catch (Throwable) {
            return null;
        }
    }

    private static function resolver(): string
    {
        try {
            return app(ResolvesSsoUser::class)::class;
        } catch (Throwable) {
            return 'unresolvable';
        }
    }
}
