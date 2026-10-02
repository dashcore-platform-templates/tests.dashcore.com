<?php

namespace Dashcore\Bridge\Sso;

use Dashcore\Bridge\Exceptions\SsoFailed;
use Dashcore\Bridge\Exceptions\SsoUnavailable;
use Dashcore\Bridge\Identity\IdentityResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * One sign-in, from the redirect out to the identity that comes back.
 *
 * The app's server does the second half: the code is exchanged here, over a
 * connection this app opened, authenticating with the Ed25519 key it already
 * holds for the bridge. Nothing in the browser ever holds a credential, and
 * there is no client secret to leak because there is no client secret.
 */
final class SsoFlow
{
    /** An assertion is a credential; it lives just long enough to be spent. */
    private const ASSERTION_LIFETIME = 60;

    public const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    public function __construct(
        private readonly Provider $provider,
        private readonly IdentityResolver $identity,
    ) {}

    /**
     * The URL to send the browser to, and the secrets this app must remember
     * until it comes back.
     *
     * @return array{url: string, state: string, verifier: string, nonce: string}
     */
    public function start(?string $prompt = null): array
    {
        $verifier = Pkce::verifier();
        $state = Str::random(40);
        $nonce = Str::random(40);

        $query = [
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->provider->redirectUri(),
            'response_type' => 'code',
            'scope' => (string) config('bridge.sso.scopes', 'openid profile email'),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => Pkce::challenge($verifier),
            'code_challenge_method' => 'S256',
        ];

        if ($prompt !== null) {
            $query['prompt'] = $prompt;
        }

        return [
            'url' => $this->provider->authorizationEndpoint().'?'.http_build_query($query),
            'state' => $state,
            'verifier' => $verifier,
            'nonce' => $nonce,
        ];
    }

    /**
     * Spend the code and read the identity out of the id_token.
     */
    public function finish(string $code, string $verifier, string $nonce): SsoIdentity
    {
        $response = $this->exchange($code, $verifier);

        $idToken = $response['id_token'] ?? null;

        if (! is_string($idToken)) {
            throw new SsoFailed('Sign-in did not complete. Please try again.', ['reason' => 'no id_token in the token response']);
        }

        return SsoIdentity::fromClaims($this->verify($idToken, $nonce));
    }

    /**
     * @return array<string, mixed>
     */
    private function exchange(string $code, string $verifier): array
    {
        try {
            $response = Http::asForm()->timeout((int) config('bridge.timeout', 10))->post($this->provider->tokenEndpoint(), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->provider->redirectUri(),
                'code_verifier' => $verifier,
                'client_id' => $this->clientId(),
                'client_assertion_type' => self::ASSERTION_TYPE,
                'client_assertion' => $this->assertion(),
            ]);
        } catch (Throwable $e) {
            throw new SsoFailed('The sign-in server could not be reached. Please try again.', ['reason' => $e->getMessage()], $e);
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body)) {
            throw new SsoFailed('Sign-in did not complete. Please try again.', [
                'reason' => 'the token endpoint refused the exchange',
                'status' => $response->status(),
                // The OAuth error code, which names a misconfiguration when
                // there is one ("invalid_client" is nearly always this app's
                // key or its redirect). Never the body: it is not ours to log.
                'error' => is_array($body) ? ($body['error'] ?? null) : null,
            ]);
        }

        return $body;
    }

    /**
     * This app, proving it is itself with the key the control plane published
     * for it (RFC 7523). One use: the `jti` is remembered by the provider
     * until the assertion would have expired anyway.
     */
    public function assertion(): string
    {
        $privateKey = $this->identity->privateKey()
            ?? throw new SsoUnavailable('This app has no bridge private key, so it cannot authenticate to the sign-in server.');

        $now = now()->getTimestamp();
        $clientId = $this->clientId();

        return Jwt::sign([
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => $this->provider->issuer(),
            'jti' => (string) Str::uuid(),
            'iat' => $now,
            'exp' => $now + self::ASSERTION_LIFETIME,
        ], $privateKey, (string) $this->identity->keyId());
    }

    /**
     * Every check the OIDC core spec puts on an id_token (§3.1.3.7), in one
     * place. A token that fails any of them is not a sign-in.
     *
     * @return array<string, mixed>
     */
    private function verify(string $idToken, string $nonce): array
    {
        $verified = Jwt::verify($idToken, $this->provider->signingKeys());

        if ($verified === null) {
            throw new SsoFailed('Sign-in did not complete. Please try again.', ['reason' => 'the id_token signature does not verify']);
        }

        $claims = $verified['claims'];
        $leeway = (int) config('bridge.clock_skew', 120);
        $now = now()->getTimestamp();
        $audience = (array) ($claims['aud'] ?? []);

        $failures = array_filter([
            ($claims['iss'] ?? null) !== $this->provider->issuer() ? 'issuer' : null,
            in_array($this->clientId(), $audience, true) ? null : 'audience',
            // A single-audience token needs no azp; one with several must say
            // which client it is for, and it must be this one.
            count($audience) > 1 && ($claims['azp'] ?? null) !== $this->clientId() ? 'azp' : null,
            ! isset($claims['exp']) || (int) $claims['exp'] + $leeway < $now ? 'expiry' : null,
            isset($claims['iat']) && (int) $claims['iat'] - $leeway > $now ? 'issued in the future' : null,
            // The nonce ties this token to the request this browser started,
            // which is what stops one replayed into somebody else's session.
            ! isset($claims['nonce']) || ! hash_equals($nonce, (string) $claims['nonce']) ? 'nonce' : null,
            ! isset($claims['sub']) || ! is_string($claims['sub']) || $claims['sub'] === '' ? 'subject' : null,
        ]);

        if ($failures !== []) {
            throw new SsoFailed('Sign-in did not complete. Please try again.', [
                'reason' => 'the id_token failed: '.implode(', ', $failures),
            ]);
        }

        return $claims;
    }

    private function clientId(): string
    {
        return $this->identity->appId()
            ?? throw new SsoUnavailable('This app has no bridge identity, so it cannot sign in through the fleet.');
    }
}
