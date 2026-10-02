<?php

namespace Dashcore\Bridge\Http\Controllers\Sso;

use Dashcore\Bridge\Sso\SsoFlow;
use Dashcore\Bridge\Sso\SsoSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The start of a sign-in: hand the browser to the fleet's sign-in server.
 *
 * The state, the PKCE verifier and the nonce are put in this app's session
 * and nowhere else. They are what make the answer that comes back provably
 * an answer to this request, from this browser.
 */
final class RedirectController
{
    public function __invoke(Request $request, SsoFlow $flow): RedirectResponse
    {
        $started = $flow->start(prompt: $request->query('prompt') === 'none' ? 'none' : null);

        SsoSession::remember($request, $started, $this->intended($request));

        return redirect()->away($started['url']);
    }

    /**
     * Where to land afterwards: what the app already recorded as the intended
     * page, else where this sign-in was asked for from, else the configured
     * home. Only paths on this host, so a crafted link cannot turn signing in
     * into a bounce to somewhere else.
     */
    private function intended(Request $request): ?string
    {
        $candidates = [$request->session()->pull('url.intended'), $request->query('redirect'), url()->previous()];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && $this->isOwn($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isOwn(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host === null || strtolower((string) $host) === strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
    }
}
