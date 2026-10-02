<?php

namespace Dashcore\Bridge\Sso;

use Illuminate\Http\Request;

/**
 * What this app has to remember between sending someone to sign in and
 * getting them back, kept in the session and read exactly once.
 */
final class SsoSession
{
    private const KEY = 'bridge.sso';

    /**
     * @param  array{state: string, verifier: string, nonce: string, url: string}  $started
     */
    public static function remember(Request $request, array $started, ?string $intended): void
    {
        $request->session()->put(self::KEY, [
            'state' => $started['state'],
            'verifier' => $started['verifier'],
            'nonce' => $started['nonce'],
            'intended' => $intended,
        ]);
    }

    /**
     * Pulled, not read: a sign-in attempt is good for one answer, so a
     * replayed callback finds nothing waiting for it.
     *
     * @return array{state: string, verifier: string, nonce: string, intended: ?string}|null
     */
    public static function take(Request $request): ?array
    {
        $pending = $request->session()->pull(self::KEY);

        return is_array($pending) && isset($pending['state'], $pending['verifier'], $pending['nonce']) ? $pending : null;
    }
}
