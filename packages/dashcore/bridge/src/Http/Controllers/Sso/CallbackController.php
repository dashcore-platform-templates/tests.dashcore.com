<?php

namespace Dashcore\Bridge\Http\Controllers\Sso;

use Dashcore\Bridge\Contracts\ResolvesSsoUser;
use Dashcore\Bridge\Events\SsoSignedIn;
use Dashcore\Bridge\Exceptions\SsoFailed;
use Dashcore\Bridge\Sso\Provider;
use Dashcore\Bridge\Sso\SsoFlow;
use Dashcore\Bridge\Sso\SsoSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * The end of a sign-in: the code comes back here and is spent server-side.
 *
 * Order matters. The pending attempt is taken from the session first, so a
 * callback nobody asked for is refused before anything is exchanged, and so
 * a second arrival of the same callback finds nothing to spend.
 */
final class CallbackController
{
    public function __invoke(Request $request, SsoFlow $flow, Provider $provider, ResolvesSsoUser $resolver): RedirectResponse
    {
        $pending = SsoSession::take($request);

        try {
            if ($pending === null) {
                throw new SsoFailed('That sign-in link has already been used. Please try again.', ['reason' => 'no pending attempt in the session']);
            }

            $state = $request->query('state');

            if (! is_string($state) || ! hash_equals($pending['state'], $state)) {
                throw new SsoFailed('Sign-in did not complete. Please try again.', ['reason' => 'state did not match']);
            }

            // RFC 9207: the response says who answered, and the sign-in
            // server advertises that it sends it. A mismatch means the answer
            // came from somewhere else.
            $issuer = $request->query('iss');

            if (is_string($issuer) && $issuer !== $provider->issuer()) {
                throw new SsoFailed('Sign-in did not complete. Please try again.', ['reason' => 'the response names another issuer']);
            }

            if (is_string($request->query('error'))) {
                throw new SsoFailed($this->explain((string) $request->query('error')), ['reason' => 'the sign-in server refused', 'error' => $request->query('error')]);
            }

            $code = $request->query('code');

            if (! is_string($code) || $code === '') {
                throw new SsoFailed('Sign-in did not complete. Please try again.', ['reason' => 'no code in the callback']);
            }

            $identity = $flow->finish($code, $pending['verifier'], $pending['nonce']);
            $user = $resolver->resolve($identity);

            if ($user === null) {
                throw new SsoFailed('Your account cannot open this app. Ask whoever runs it for access.', [
                    'reason' => 'no local user for this subject',
                    'subject' => $identity->subject,
                ]);
            }

            Auth::guard(config('bridge.sso.guard') ?: null)->login($user, remember: (bool) config('bridge.sso.remember', true));

            // A fresh session id for the signed-in session: the id that
            // carried the sign-in must not be the one that carries the
            // account.
            $request->session()->regenerate();

            event(new SsoSignedIn($user, $identity));

            return redirect()->intended($pending['intended'] ?? (string) config('bridge.sso.home', '/'));
        } catch (SsoFailed $failure) {
            Log::warning('Sign-in through the fleet failed: '.$failure->getMessage(), $failure->context);

            return redirect()->to($this->failureRedirect())
                ->with('bridge.sso.error', $failure->getMessage());
        }
    }

    /**
     * The app's sign-in page, unless the app says otherwise: that is the page
     * that shows `bridge.sso.error`, and with sso required it lets a failed
     * sign-in stop there instead of going round again (SendSignInToSso).
     */
    private function failureRedirect(): string
    {
        $configured = config('bridge.sso.failure_redirect');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $login = ((array) config('bridge.sso.login_routes', ['login', 'login.store']))[0] ?? null;

        return is_string($login) && Route::has($login) ? route($login) : '/';
    }

    /**
     * The provider's error code, said in words the person can act on. Only
     * the two an ordinary person can actually hit are named.
     */
    private function explain(string $error): string
    {
        return match ($error) {
            'access_denied' => 'Your account cannot open this app. Ask whoever runs it for access.',
            'login_required' => 'Please sign in again.',
            default => 'Sign-in did not complete. Please try again.',
        };
    }
}
