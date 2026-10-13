<?php

namespace Dashcore\Bridge\Sso;

use Dashcore\Bridge\Exceptions\SsoUnavailable;
use Dashcore\Bridge\Identity\IdentityResolver;
use Dashcore\Bridge\Keys\KeysetResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The sign-in server, as this app sees it.
 *
 * Two separate questions, answered from two separate places on purpose:
 *
 * - **Where it is and what it offers** comes from its discovery document,
 *   which is public and fetched unsigned. It is held to the address the
 *   manifest publishes: a discovery document is only ever trusted to
 *   describe the host it was served by.
 * - **Which keys sign its tokens** comes from the fleet manifest, which is
 *   already signed by the control plane and already in this app's hands.
 *   Reading the keys from the provider's own JWKS would mean trusting the
 *   thing being verified to publish the means of verifying it.
 */
final class Provider
{
    private const DISCOVERY_PATH = '/.well-known/openid-configuration';

    public function __construct(
        private readonly IdentityResolver $identity,
        private readonly KeysetResolver $keys,
    ) {}

    /** The sign-in server's app id, which is its hostname. */
    public function appId(): string
    {
        $configured = config('bridge.sso.provider');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        // Same domain, the `login` host: an install whose apps are
        // team.acme.com and hr.acme.com signs in at login.acme.com.
        $ours = $this->identity->appId()
            ?? throw new SsoUnavailable('This app has no bridge identity, so it cannot sign in through the fleet.');

        $domain = implode('.', array_slice(explode('.', $ours), 1));

        return $domain === '' ? 'login' : 'login.'.$domain;
    }

    /**
     * This app's own address, as the fleet publishes it. Falls back to
     * APP_URL when the manifest does not carry this app — the control plane
     * publishes the manifest but is not a peer of itself.
     */
    public function selfUrl(): string
    {
        $appId = $this->identity->appId()
            ?? throw new SsoUnavailable('This app has no bridge identity, so it cannot sign in through the fleet.');

        $url = $this->keys->urlFor($appId) ?? config('app.url');

        return rtrim($url ?? throw new SsoUnavailable("The manifest publishes no address for {$appId}, and APP_URL is not set."), '/');
    }

    /**
     * Where the sign-in server sends people back to. Built from the manifest
     * rather than from this app's own config: the provider matches it exactly
     * against what the manifest says, so a URL assembled from APP_URL that
     * differs by so much as a trailing slash is refused at the door.
     */
    public function redirectUri(): string
    {
        return $this->selfUrl().'/'.ltrim((string) config('bridge.sso.callback_path', 'auth/sso/callback'), '/');
    }

    public function authorizationEndpoint(): string
    {
        return $this->endpoint('authorization_endpoint');
    }

    public function tokenEndpoint(): string
    {
        return $this->endpoint('token_endpoint');
    }

    public function issuer(): string
    {
        $issuer = $this->discovery()['issuer'] ?? null;

        return is_string($issuer) ? $issuer : throw new SsoUnavailable('The sign-in server published no issuer.');
    }

    /**
     * The keys the manifest publishes for the sign-in server.
     *
     * @return array<string, string>
     */
    public function signingKeys(): array
    {
        $keys = $this->keys->publicKeysFor($this->appId());

        return $keys !== [] ? $keys : throw new SsoUnavailable(
            'The manifest publishes no keys for '.$this->appId().', so an id_token from it cannot be checked.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function discovery(): array
    {
        $url = rtrim($this->keys->urlFor($this->appId()) ?? throw new SsoUnavailable(
            'The manifest publishes no address for '.$this->appId().'. Enrol it in the fleet first.'
        ), '/');

        $document = Cache::remember(
            'bridge:sso:discovery:'.$url,
            (int) config('bridge.sso.discovery_ttl', 300),
            fn () => $this->fetch($url),
        );

        $this->assertServedBy($document, $url);

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $url): array
    {
        try {
            $response = Http::timeout((int) config('bridge.timeout', 10))->get($url.self::DISCOVERY_PATH);
        } catch (Throwable $e) {
            throw new SsoUnavailable('The sign-in server is unreachable: '.$e->getMessage(), previous: $e);
        }

        $document = $response->successful() ? $response->json() : null;

        return is_array($document) ? $document : throw new SsoUnavailable(
            'The sign-in server did not serve a discovery document (HTTP '.$response->status().').'
        );
    }

    /**
     * Every address a client is told to use has to belong to the host the
     * manifest names. Otherwise a discovery document is an open redirect with
     * extra steps: it could point authorization at anywhere at all.
     *
     * @param  array<string, mixed>  $document
     */
    private function assertServedBy(array $document, string $url): void
    {
        $expected = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (['issuer', 'authorization_endpoint', 'token_endpoint'] as $claim) {
            $value = $document[$claim] ?? null;

            if (! is_string($value) || strtolower((string) parse_url($value, PHP_URL_HOST)) !== $expected) {
                throw new SsoUnavailable("The sign-in server's {$claim} is not on {$expected}.");
            }
        }
    }

    private function endpoint(string $name): string
    {
        $endpoint = $this->discovery()[$name] ?? null;

        return is_string($endpoint) ? $endpoint : throw new SsoUnavailable("The sign-in server published no {$name}.");
    }
}
