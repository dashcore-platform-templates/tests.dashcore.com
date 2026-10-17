<?php

namespace Dashcore\DesignKit;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Proof of which repository this is, from GitHub rather than from a secret.
 *
 * A workflow that asks for `permissions: id-token: write` gets two variables
 * in its environment: a URL to mint a token from, and a token to mint it with.
 * The document that comes back names the repository, the ref and the audience,
 * signed by GitHub. design.dashcore.com verifies the signature and reads the
 * repository out of it.
 *
 * That is the whole reason there is no DESIGN_TOKEN to paste any more. The
 * repository does not hold a credential; GitHub vouches for it each run.
 *
 * Only inside GitHub Actions. Anywhere else both variables are missing and
 * this returns null, which is not a failure: a person running the audit on
 * their own machine has nothing to report from and does not need to.
 */
final class ActionsToken
{
    /**
     * A token naming the audience, or null when this is not a GitHub Actions
     * job with id-token permission.
     *
     * The audience is the site being reported to, so a token minted for one
     * design.dashcore.com is not a token for a different one.
     */
    public static function forAudience(string $audience): ?string
    {
        $url = getenv('ACTIONS_ID_TOKEN_REQUEST_URL') ?: null;
        $requestToken = getenv('ACTIONS_ID_TOKEN_REQUEST_TOKEN') ?: null;

        if ($url === null || $requestToken === null) {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->withToken($requestToken)
                ->acceptJson()
                ->get($url, ['audience' => rtrim($audience, '/')]);
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $value = $response->json('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Whether this is running inside GitHub Actions at all.
     *
     * Told apart from having the permission, because the two need different
     * things said about them: somewhere else entirely, or the right place with
     * a line missing from the workflow.
     */
    public static function inActions(): bool
    {
        return (getenv('GITHUB_ACTIONS') ?: '') === 'true';
    }

    /**
     * Whether the workflow asked for the permission.
     *
     * The common setup mistake, and the one worth naming outright: everything
     * else is in place and `permissions: id-token: write` is missing, so the
     * variables never arrive.
     */
    public static function hasPermission(): bool
    {
        return (getenv('ACTIONS_ID_TOKEN_REQUEST_URL') ?: '') !== '';
    }
}
