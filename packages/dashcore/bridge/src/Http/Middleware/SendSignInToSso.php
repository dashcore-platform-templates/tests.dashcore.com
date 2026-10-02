<?php

namespace Dashcore\Bridge\Http\Middleware;

use Closure;
use Dashcore\Bridge\Sso\PasswordSignIn;
use Dashcore\Bridge\Sso\SsoMode;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With sso required, opening the app's sign-in page goes straight to the
 * fleet's sign-in. Two ways past, both on purpose:
 *
 * - `?local`, for the break-glass accounts: the app's own form, which still
 *   refuses everyone else's password (PasswordSignIn).
 * - a sign-in that just failed: the page shows why, instead of sending the
 *   person straight back to a sign-in that will fail the same way.
 */
final class SendSignInToSso
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')
            && SsoMode::isRequired()
            && PasswordSignIn::isPasswordSignInRequest($request)
            && ! $request->query->has('local')
            && ! $request->session()->has('bridge.sso.error')) {
            return redirect()->route('bridge.sso.redirect');
        }

        return $next($request);
    }
}
