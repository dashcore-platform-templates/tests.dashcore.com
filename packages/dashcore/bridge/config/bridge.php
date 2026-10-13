<?php

return [

    /*
    |--------------------------------------------------------------------------
    | This app's bridge identity
    |--------------------------------------------------------------------------
    |
    | Every app in the fleet has a stable slug, an Ed25519 keypair generated
    | by `bridge:keys:generate`, and a key ID so peers can verify against the
    | right public key during rotation. The private key never leaves this app.
    */

    'app_id' => env('BRIDGE_APP_ID'),

    'private_key' => env('BRIDGE_PRIVATE_KEY'),

    'key_id' => env('BRIDGE_KEY_ID'),

    /*
    |--------------------------------------------------------------------------
    | Keyset driver
    |--------------------------------------------------------------------------
    |
    | `control` resolves peers, keys, and grants from the fleet manifest
    | published and signed by the control plane (api.dashcore.com). `config`
    | reads the static peers/grants arrays below — local dev and break-glass.
    */

    // Unset, the driver follows the evidence: a control URL means this is a
    // fleet app resolving peers from the manifest.
    'driver' => env('BRIDGE_DRIVER') ?: (env('BRIDGE_CONTROL_URL') ? 'control' : 'config'),

    'control' => [
        'url' => env('BRIDGE_CONTROL_URL'),
        'public_key' => env('BRIDGE_CONTROL_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fleet key (zero-paste enrollment)
    |--------------------------------------------------------------------------
    |
    | With this and the control URL set, `bridge:connect` self-enrolls: the
    | keypair is generated locally, the control plane's public key is learned
    | from the handshake, and the whole identity is stored encrypted in this
    | app's database. No other BRIDGE_* env is needed.
    */

    'fleet_key' => env('BRIDGE_FLEET_KEY'),

    'manifest_ttl' => env('BRIDGE_MANIFEST_TTL', 300),

    // How old the last good manifest may be and still be served while a
    // fresh one is fetched after the response. Past this a caller waits for
    // the control plane again, so a revoked key cannot outlive it by more.
    'manifest_max_stale' => env('BRIDGE_MANIFEST_MAX_STALE', 3600),

    /*
    |--------------------------------------------------------------------------
    | Peers (static keyset driver)
    |--------------------------------------------------------------------------
    |
    | Known peers keyed by app ID. Each entry carries the peer's base URL and
    | its published public keys keyed by key ID. In production this map is
    | superseded by the control-plane fleet manifest; the static driver stays
    | for local development and break-glass fallback.
    */

    'peers' => [
        // 'crm' => [
        //     'url' => env('BRIDGE_PEER_CRM_URL'),
        //     'keys' => ['crm-2026-07' => 'base64-public-key'],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound authorization
    |--------------------------------------------------------------------------
    |
    | Fleet membership is the authorization. A validly signed request comes
    | from an enrolled member of this fleet, and members may call one
    | another freely — the ability on each route is a label for the audit
    | trail and the endpoint directory, not a gate.
    |
    | Turn `enforce_scope` on to police abilities individually instead. The
    | grants below (and those published in the signed manifest) are still
    | resolved either way, so this is a switch rather than a migration.
    */

    'enforce_scope' => env('BRIDGE_ENFORCE_SCOPE', false),

    'grants' => [
        // 'crm' => ['contacts.read', 'contacts.write'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Replay protection
    |--------------------------------------------------------------------------
    */

    'clock_skew' => env('BRIDGE_CLOCK_SKEW', 120),

    'nonce_ttl' => env('BRIDGE_NONCE_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Plaintext HTTP is refused unless explicitly allowed (local dev only).
    */

    'allow_insecure' => env('BRIDGE_ALLOW_INSECURE', false),

    'timeout' => env('BRIDGE_TIMEOUT', 10),

    // A call that never reached the peer — it could not connect, or the
    // connection timed out before anything was sent — is tried again this
    // many times, freshly signed each time. A blip on the network between
    // two apps is not an outage, and reporting it as one failed the fleet's
    // readiness bar for a day (7 Oct 2026). A call the peer did receive is
    // never retried here: it may have acted on it.
    'connect_retries' => (int) env('BRIDGE_CONNECT_RETRIES', 1),

    /*
    |--------------------------------------------------------------------------
    | Health surface
    |--------------------------------------------------------------------------
    |
    | Serves GET /api/bridge/v1/health, guarded by the `bridge.health` ability
    | so it is readable only by a peer that has been granted it. On by default:
    | fleet-wide observability that each app has to opt into is observability
    | most of the fleet will not have.
    |
    | Turn it off in an app that serves its own richer health route at the same
    | path, so the two never both register it.
    |
    */

    'health' => [
        'enabled' => (bool) env('BRIDGE_HEALTH_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | The control plane's own app id
    |--------------------------------------------------------------------------
    |
    | Which peer is the hub. Used when this app reports its inbound call log,
    | so the fleet's traffic graph can be assembled somewhere — no single app
    | can see more than its own half of it.
    |
    */

    'control_app' => env('BRIDGE_CONTROL_APP', 'api'),

    /*
    |--------------------------------------------------------------------------
    | Signing people in through the fleet
    |--------------------------------------------------------------------------
    |
    | One account, one passkey, every app: people sign in at the fleet's
    | sign-in server and come back here with an id_token this app checks
    | against the keys the manifest already publishes. There is no client
    | secret — this app proves it is itself with the bridge key it already
    | holds.
    |
    | Off by default, and rolled one app at a time (see SsoMode):
    |
    | - off:      the app's own sign-in only.
    | - optional: adds the redirect and callback routes; the app keeps its own
    |             sign-in screen and links to the fleet's from it.
    | - required: the app's sign-in page goes to the fleet's, and its own
    |             password is refused for everyone but the break-glass
    |             accounts, who reach the form at `/login?local`.
    |
    | What the routes do with the person who arrives is the app's own
    | `ResolvesSsoUser`.
    |
    */

    'sso' => [
        'mode' => env('BRIDGE_SSO_MODE'),

        // Before modes existed: true is `optional`. BRIDGE_SSO_MODE wins.
        'enabled' => (bool) env('BRIDGE_SSO', false),

        // Who keeps a password when sso is required: a comma-separated list
        // of values of the username field. At least one, or required is
        // served as optional — the sign-in server being down must never lock
        // the owner out.
        'break_glass' => env('BRIDGE_SSO_BREAK_GLASS', ''),

        // The field the app's sign-in form names people by, and the routes
        // that serve and receive that form (Breeze and Fortify name them so).
        'username' => env('BRIDGE_SSO_USERNAME', 'email'),
        'login_routes' => ['login', 'login.store'],

        // The sign-in server's app id, which is its hostname. Unset, it is
        // the `login` host of this app's own domain.
        'provider' => env('BRIDGE_SSO_PROVIDER'),

        // The path the provider sends people back to. It is matched there
        // exactly against the address the manifest publishes for this app,
        // so changing it means changing it on both sides.
        'callback_path' => env('BRIDGE_SSO_CALLBACK_PATH', 'auth/sso/callback'),
        'redirect_path' => env('BRIDGE_SSO_REDIRECT_PATH', 'auth/sso/redirect'),

        'scopes' => env('BRIDGE_SSO_SCOPES', 'openid profile email'),

        // Where to land after signing in, when nothing else was intended,
        // and where to send someone whose sign-in did not complete. Unset,
        // a failure goes to the app's sign-in page (the first login route),
        // which is where the reason is shown; `/` when there is none.
        'home' => env('BRIDGE_SSO_HOME', '/'),
        'failure_redirect' => env('BRIDGE_SSO_FAILURE_REDIRECT'),

        'guard' => env('BRIDGE_SSO_GUARD'),
        'remember' => (bool) env('BRIDGE_SSO_REMEMBER', true),

        // Where this app writes the provider's permanent handle for a person.
        // Absent, people are matched by verified email every time.
        'subject_column' => env('BRIDGE_SSO_SUBJECT_COLUMN', 'sso_subject'),

        // Whether somebody who can sign in at the provider, but has no
        // account here, gets one made. Off: in most apps an account carries
        // roles or a seat, and those are decisions, not side effects.
        'provision' => (bool) env('BRIDGE_SSO_PROVISION', false),
        'user_model' => env('BRIDGE_SSO_USER_MODEL'),

        // How long the provider's discovery document is held before it is
        // fetched again.
        'discovery_ttl' => (int) env('BRIDGE_SSO_DISCOVERY_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | What this app reads from its peers
    |--------------------------------------------------------------------------
    |
    | Peer => path => the dot-notation fields this app depends on. Checked by
    | `bridge:check-contracts`, which fails when a peer stops publishing one.
    |
    | This exists because a consumer reading a key its producer does not send
    | fails silently: the value is null, null renders as an empty panel, and an
    | empty panel is indistinguishable from a peer with nothing to say. Every
    | producer-side check stays green throughout. Declaring the dependency is
    | what turns that into an error somebody sees.
    |
    |   'marketing.dashcore.com.test' => [
    |       '/snapshot' => ['leads.total', 'campaigns'],
    |   ],
    |
    */

    'expects' => [],

    /*
    |--------------------------------------------------------------------------
    | Telemetry — errors and slow routes
    |--------------------------------------------------------------------------
    |
    | Every app records what went wrong inside it and how long its routes took,
    | in hourly buckets, and ships the closed ones to a collector. No app can
    | see more than its own half of the fleet, so this is the only arrangement
    | that makes "is anything broken anywhere" answerable in one place.
    |
    | What travels is the shape of a failure — exception class, redacted
    | message, file, line, route pattern, counts — and never its contents. See
    | Dashcore\Bridge\Telemetry\Redactor for what is stripped, and note that
    | it is stripped on write: the raw text stays in that app's own log, on
    | that app's own disk, which is where it belongs.
    |
    */

    'telemetry' => [

        // Recording. Off means no rows are written at all.
        'enabled' => env('BRIDGE_TELEMETRY', true),

        // Whether to also time ordinary requests, not just record failures.
        // Separable because the costs differ: error capture writes only when
        // something is already wrong, request capture touches a row on every
        // request.
        'requests' => env('BRIDGE_TELEMETRY_REQUESTS', true),

        // Past this, a request counts as slow. Reported alongside the figures
        // so the collector can say "slow" in each app's own terms rather than
        // imposing one number on thirteen apps with very different jobs.
        'slow_request_ms' => env('BRIDGE_TELEMETRY_SLOW_MS', 1000),

        // The app that collects this — a fleet app key, resolved to this
        // environment's hostname the way every other peer is.
        'collector' => env('BRIDGE_TELEMETRY_COLLECTOR', 'health'),

        // How long a reported bucket is kept locally. The local copy exists to
        // survive a collector that is down, not to be an archive; the
        // collector holds the history.
        'retention_days' => env('BRIDGE_TELEMETRY_RETENTION_DAYS', 7),

        // Paths never worth a row. The telemetry ingest itself is on this list
        // deliberately: a collector that records its own collection endpoint
        // makes traffic that the next report has to describe, and the table
        // never settles.
        'ignore' => [
            'api/bridge/v1/ping',
            'api/bridge/v1/health',
            'api/bridge/v1/telemetry',
            'api/bridge/v1/emails',
            'up',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Mail ledger
    |--------------------------------------------------------------------------
    |
    | Every email this app sends is written down before it is handed to the
    | transport and shipped to the telemetry collector (`health`), which joins
    | it to what the provider says happened next. Catches the sends a provider
    | never sees: a missing key, a network failure, a mailer left on `log`.
    |
    */

    'mail' => [
        'enabled' => env('BRIDGE_MAIL_LEDGER', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security headers
    |--------------------------------------------------------------------------
    |
    | The headers `standards` grades every app for, set once here instead of
    | in twenty-one apps that each forgot (its first full run, 2026-10-07:
    | every app missing every one). Each value can be changed per app by its
    | env variable, or set to null to send nothing for that header.
    |
    | Chosen not to break anything the fleet already does: the CSP restricts
    | framing, <base> and plugins, not scripts or styles; camera and
    | microphone stay open to the app itself (ceo's calls use both), and
    | payment is left alone so Stripe's wallet buttons work (dcos); COOP
    | still allows the popups OAuth and payment flows open. HSTS is sent on
    | HTTPS requests only. COEP is not sent — it breaks third-party embeds,
    | and `standards` treats it as optional.
    |
    */

    'security_headers' => [
        'enabled' => env('BRIDGE_SECURITY_HEADERS', true),
        'strict_transport_security' => env('BRIDGE_HSTS', 'max-age=31536000'),
        'content_security_policy' => env('BRIDGE_CSP', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'"),
        'referrer_policy' => env('BRIDGE_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'permissions_policy' => env('BRIDGE_PERMISSIONS_POLICY', 'camera=(self), microphone=(self), geolocation=(), usb=()'),
        'cross_origin_opener_policy' => env('BRIDGE_COOP', 'same-origin-allow-popups'),
        'cross_origin_resource_policy' => env('BRIDGE_CORP', 'same-site'),
        'x_dns_prefetch_control' => env('BRIDGE_DNS_PREFETCH_CONTROL', 'off'),
    ],

    /*
    | /.well-known/security.txt (RFC 9116): where to report a vulnerability.
    | The owner chose the contact on 2026-10-07. Null serves no file, and an
    | app that ships its own public/.well-known/security.txt is served that
    | file by the web server before this route is reached.
    */

    'security_txt' => [
        'contact' => env('BRIDGE_SECURITY_CONTACT', 'mailto:travis@dashcore.com'),
    ],

];
