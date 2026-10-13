<?php

use Dashcore\Bridge\BridgeVersion;
use Dashcore\Bridge\Http\Controllers\HealthController;
use Dashcore\Bridge\Http\Controllers\Sso\CallbackController;
use Dashcore\Bridge\Http\Controllers\Sso\RedirectController;
use Dashcore\Bridge\Identity\IdentityResolver;
use Dashcore\Bridge\Sso\SsoMode;
use Illuminate\Support\Facades\Route;

Route::prefix('api/bridge/v1')
    ->middleware(['bridge.auth', 'bridge.scope:bridge.ping'])
    ->get('ping', function () {
        return response()->json([
            'pong' => true,
            'app' => app(IdentityResolver::class)->appId(),
            'version' => BridgeVersion::version(),
            'environment' => app()->environment(),
            'time' => now()->toIso8601ZuluString(),
            // The clock this app runs and stores on. The fleet keeps one —
            // the company's — and the hub compares what each peer says here
            // against its own, so a peer on another zone is a Health finding
            // rather than a page that is quietly hours out.
            'timezone' => (string) config('app.timezone'),
            // How people sign in here (off, optional or required), so the
            // hub can report each app's posture as the rollout moves.
            'sso' => SsoMode::current(),
        ], 200, ['Cache-Control' => 'no-store']);
    })
    ->name('bridge.ping');

// The default health surface. An app with a richer notion of healthy sets
// `bridge.health.enabled` to false and serves its own route at this path —
// the toggle exists so the two never register the same URI and leave which
// one answers up to provider ordering.
if (config('bridge.health.enabled', true)) {
    Route::prefix('api/bridge/v1')
        ->middleware(['bridge.auth', 'bridge.scope:bridge.health'])
        ->get('health', HealthController::class)
        ->name('bridge.health');
}

// Signing people in through the fleet's sign-in server. Two routes, added
// only when the install turns sso on (optional or required): the path an app
// sends someone to, and the exact path the provider is told to send them
// back to.
if (SsoMode::isOn()) {
    Route::middleware('web')->group(function () {
        Route::get(config('bridge.sso.redirect_path', 'auth/sso/redirect'), RedirectController::class)->name('bridge.sso.redirect');
        Route::get(config('bridge.sso.callback_path', 'auth/sso/callback'), CallbackController::class)->name('bridge.sso.callback');
    });
}

// Where to report a vulnerability (RFC 9116), so every app publishes one
// without each remembering to. An app that ships its own static file is
// served that by the web server first; a null contact serves nothing.
if (filled(config('bridge.security_txt.contact'))) {
    Route::get('.well-known/security.txt', function () {
        $contact = (string) config('bridge.security_txt.contact');

        return response(implode("\n", [
            'Contact: '.(str_contains($contact, ':') ? $contact : 'mailto:'.$contact),
            'Expires: '.now()->addYear()->startOfDay()->toIso8601ZuluString(),
            'Preferred-Languages: en',
            'Canonical: '.url('/.well-known/security.txt'),
        ])."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=86400']);
    })->name('bridge.security-txt');
}
