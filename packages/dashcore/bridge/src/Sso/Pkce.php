<?php

namespace Dashcore\Bridge\Sso;

use Illuminate\Support\Str;

/**
 * Proof Key for Code Exchange (RFC 7636), S256 only.
 *
 * The verifier never leaves this app until the code is exchanged from its
 * server, so a code stolen out of a redirect — from a log, a referrer header,
 * a shoulder — cannot be spent by whoever stole it.
 */
final class Pkce
{
    public static function verifier(): string
    {
        return Str::random(64);
    }

    public static function challenge(string $verifier): string
    {
        return Jwt::base64UrlEncode(hash('sha256', $verifier, binary: true));
    }
}
