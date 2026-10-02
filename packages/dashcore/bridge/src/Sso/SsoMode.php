<?php

namespace Dashcore\Bridge\Sso;

use Illuminate\Support\Facades\Log;

/**
 * How this app lets people in: its own password, the fleet's sign-in, or
 * both. Rolled one app at a time — flip one, verify, flip the next.
 *
 * - off:      the app's own sign-in only. No sso routes are served.
 * - optional: both. The app's sign-in screen stays and links to the fleet's.
 * - required: the fleet's sign-in only, except for the break-glass accounts
 *             (BRIDGE_SSO_BREAK_GLASS). Those keep their password so that the
 *             sign-in server being down stops new sign-ins, never the owner.
 *
 * `required` with no break-glass account is served as `optional`, and says
 * so in the log and in ping: an app whose only way in depends on another app
 * is the lock-out this rollout exists to avoid.
 */
final class SsoMode
{
    public const OFF = 'off';

    public const OPTIONAL = 'optional';

    public const REQUIRED = 'required';

    /** The mode as configured, before the break-glass rule is applied. */
    public static function configured(): string
    {
        $mode = config('bridge.sso.mode');

        if (is_string($mode) && trim($mode) !== '') {
            $mode = strtolower(trim($mode));

            // An unrecognised value turns sso off rather than guessing which
            // of the others was meant; ping reports it, so it is not silent.
            return in_array($mode, [self::OFF, self::OPTIONAL, self::REQUIRED], true) ? $mode : self::OFF;
        }

        // Before modes, BRIDGE_SSO=true meant "add the routes, keep the app's
        // own sign-in", which is optional.
        return config('bridge.sso.enabled', false) ? self::OPTIONAL : self::OFF;
    }

    /** The mode actually enforced. */
    public static function current(): string
    {
        $mode = self::configured();

        if ($mode === self::REQUIRED && self::breakGlass() === []) {
            Log::warning('BRIDGE_SSO_MODE is required but BRIDGE_SSO_BREAK_GLASS names nobody; serving optional so the owner cannot be locked out.');

            return self::OPTIONAL;
        }

        return $mode;
    }

    public static function isOn(): bool
    {
        return self::current() !== self::OFF;
    }

    public static function isRequired(): bool
    {
        return self::current() === self::REQUIRED;
    }

    /**
     * Whether this account may still sign in with the app's own password.
     */
    public static function allowsPasswordFor(?string $identifier): bool
    {
        if (! self::isRequired()) {
            return true;
        }

        return $identifier !== null && in_array(strtolower(trim($identifier)), self::breakGlass(), true);
    }

    /**
     * The accounts that keep their password when sso is required, by the
     * value of the app's username field (normally the email address).
     *
     * @return list<string>
     */
    public static function breakGlass(): array
    {
        $configured = config('bridge.sso.break_glass', []);
        $list = is_array($configured) ? $configured : explode(',', (string) $configured);

        return array_values(array_filter(array_map(fn ($v) => strtolower(trim((string) $v)), $list)));
    }
}
