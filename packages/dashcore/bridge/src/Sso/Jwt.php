<?php

namespace Dashcore\Bridge\Sso;

use Dashcore\Bridge\Crypto\Signature;

/**
 * The little of JWT this package needs, in Ed25519 only.
 *
 * Written here rather than pulled in as a dependency because the whole of it
 * is base64url, JSON and the signing primitive the bridge already uses: an
 * app proves who it is to the sign-in server with the same key it signs
 * every bridge call with, and reads the answer with the key the manifest
 * publishes for the sign-in server. No other algorithm is accepted anywhere
 * in this file — "alg": "none" and an HMAC keyed with the public key are the
 * two classic ways a JWT library is talked out of checking a signature.
 */
final class Jwt
{
    public const ALGORITHM = 'EdDSA';

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function sign(array $claims, string $privateKey, string $keyId): string
    {
        $signingInput = self::encode(['typ' => 'JWT', 'alg' => self::ALGORITHM, 'kid' => $keyId])
            .'.'.self::encode($claims);

        return $signingInput.'.'.self::base64UrlEncode(
            (string) base64_decode(Signature::sign($signingInput, $privateKey), strict: true)
        );
    }

    /**
     * The token's header and claims once the signature verifies against one
     * of the given keys, or null when it does not.
     *
     * @param  array<string, string>  $publicKeys  key id => base64 Ed25519 public key
     * @return array{header: array<string, mixed>, claims: array<string, mixed>}|null
     */
    public static function verify(string $token, array $publicKeys): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;

        $header = self::decode($encodedHeader);
        $claims = self::decode($encodedClaims);
        $signature = self::base64UrlDecode($encodedSignature);

        if ($header === null || $claims === null || $signature === null) {
            return null;
        }

        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            return null;
        }

        // The header names a key when the signer has more than one; it is a
        // hint, not an authority, so a header naming a key the publisher does
        // not have falls back to trying every key it does.
        $keyId = $header['kid'] ?? null;
        $candidates = is_string($keyId) && isset($publicKeys[$keyId])
            ? [$publicKeys[$keyId]]
            : array_values($publicKeys);

        $signingInput = $encodedHeader.'.'.$encodedClaims;

        foreach ($candidates as $publicKey) {
            if (Signature::verify($signingInput, base64_encode($signature), $publicKey)) {
                return ['header' => $header, 'claims' => $claims];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function encode(array $payload): string
    {
        return self::base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(string $segment): ?array
    {
        $json = self::base64UrlDecode($segment);
        $decoded = $json === null ? null : json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), strict: true);

        return $decoded === false ? null : $decoded;
    }
}
