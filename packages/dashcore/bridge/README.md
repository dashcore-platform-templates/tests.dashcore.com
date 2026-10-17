# dashcore/bridge

Secure Laravel-to-Laravel API access for the Dashcore fleet: Ed25519 signed
requests, replay protection, and audit logging. Fleet membership is the
authorization.

The calling application holds a private key; the control plane stores only the
public half. No secret capable of producing a signature ever leaves the
application that owns it.

## Installing in a connecting site

The repository is private, so a site carries the release it uses in its own
repository instead of downloading it — a GitHub Actions runner or a Laravel
Cloud build server cannot clone a private repo. From a checkout of this
repository:

```bash
bin/vendor-into ~/Herd/some-site            # newest release
bin/vendor-into ~/Herd/some-site v0.11.14   # a specific one
```

That copies the release's runtime files into `packages/dashcore/bridge`,
points the site's `composer.json` at them through a path repository, keeps
Pint off them, and runs `composer update dashcore/bridge`. Commit `packages/`,
`composer.json`, `composer.lock` and `pint.json` together. Upgrading later is
the same command with a newer tag.

No secret ships in the package. Every real secret — `BRIDGE_PRIVATE_KEY`,
fleet keys — lives in each app's environment or database, never here.

## Enrolling

Mint a one-time token on the control plane:

```bash
# on api.dashcore.com
php artisan platform:enroll-token <slug> --company=<company> --name="<Name>"
```

Redeem it from the connecting site. The keypair is generated locally and the
private half never crosses the network:

```bash
php artisan bridge:install --token=<token> --control=https://api.dashcore.com
```

The command prints an env block to paste in:

```dotenv
BRIDGE_APP_ID=your-app-slug
BRIDGE_KEY_ID=your-app-slug-2026-08
BRIDGE_PRIVATE_KEY=<base64 Ed25519 secret key>

BRIDGE_DRIVER=control
BRIDGE_CONTROL_URL=https://api.dashcore.com
BRIDGE_CONTROL_KEY=<control plane public key, returned by enrolment>
```

`BRIDGE_PRIVATE_KEY` is the only real secret here. `BRIDGE_KEY_ID` and the
public key are not secrets — they select which key to verify against.

`BRIDGE_PRIVATE_KEY` is what makes this app a member of the fleet, and
membership is the authorization — see below.

## Enrolling as part of a deploy

An app that is given a fleet key can join the fleet by itself. Put this in
the deploy script, after `migrate`:

```bash
php artisan bridge:connect --if-needed
```

Two environment values are all it needs, and nothing has to be pasted back:

```dotenv
BRIDGE_FLEET_KEY=fleet_…                      # mint on the hub: platform:fleet-key
BRIDGE_CONTROL_URL=https://api.dashcore.com
```

`--if-needed` is the deploy contract: enroll when this app can, and never be
the reason a deploy fails when it cannot. Already enrolled, no fleet key, a
hub that does not answer, an enrolment the hub refuses — each reports and
exits 0, and the next deploy tries again. The one exception is a missing
`APP_KEY`, because the identity is stored encrypted with it and nothing
could be kept.

Without the flag every one of those is a failure, which is what you want
when you ran the command yourself.

The app lands on the hub as **pending**; an operator approves it there
(`platform:apply-plan --approve`), which is also where its grants are
created.

## Calling the platform

```php
use Dashcore\Bridge\Facades\Bridge;

$response = Bridge::to('api')->get('/api/v1/me');

$response = Bridge::to('api')->post('/api/v1/events', ['type' => 'user.created']);
```

`get`, `post`, `put`, `patch` and `delete` are available, plus `timeout()` and
`retry()`. Signing, nonces, timestamps and the canonical string are handled for
you — including the one detail that breaks hand-rolled clients, which is that
the body must be transmitted byte-for-byte as it was hashed.

## Receiving calls from the fleet

```php
Route::middleware(['bridge.auth', 'bridge.scope:reports.read'])
    ->get('/api/bridge/v1/reports', ReportController::class);
```

`bridge.auth` is the gate. It rejects anything that cannot prove fleet
membership: an unknown app, an unknown or revoked key, a stale timestamp, a
bad signature, or a reused nonce. All of those are a `401` before your
controller runs.

`bridge.scope:reports.read` is a **label, not a gate**. Fleet membership is
the authorization: a caller that has cryptographically proven it is an
enrolled member may call any endpoint of any other member. The ability names
the capability in the audit trail and in the fleet's endpoint directory,
which is what reads it.

That is a deliberate posture, and it rests on revocation rather than
scoping. Revoke a credential and it leaves the signed manifest, so the
caller is refused at the door instead of admitted and narrowed afterwards.
Because the manifest is cached (`BRIDGE_MANIFEST_TTL`, 300s), that is how
long a revocation takes to reach the whole fleet — the number worth knowing
if you are relying on it.

To police abilities individually instead:

```env
BRIDGE_ENFORCE_SCOPE=true
```

Grants are still resolved from the manifest either way, so this is a switch
rather than a migration.

The package ships one route already — `GET /api/bridge/v1/ping`, labelled
`bridge.ping` — so a peer can prove reachability without exposing anything.

## Checking it works

```bash
php artisan bridge:doctor
```

Reports this app's identity, manifest freshness, and whether every peer is
reachable. Run it first whenever a call starts failing.

## Keys

```bash
php artisan bridge:keys:generate   # a fresh keypair, printed as an env block
php artisan bridge:keys:rotate     # register a new key, signed under the current one
```

Rotation is overlap-then-retire, never swap-in-place: the new credential goes
live immediately and the outgoing one keeps verifying for a grace period
(24 hours by default), so a fleet rolling one instance at a time never hits a
window where neither key is accepted.

## Configuration

`BRIDGE_DRIVER=control` resolves peers, keys and grants from the fleet manifest
published and signed by the control plane. `config` reads static arrays from
`config/bridge.php` instead — local development and break-glass only.

`BRIDGE_MANIFEST_TTL` (default 300s) is how long a cached manifest is trusted,
and therefore how long a revocation elsewhere in the fleet takes to be noticed
here.

## The fleet catalogue

`Dashcore\Bridge\Fleet\Catalogue` is the one list of every app a fleet can
have: its key, subdomain, group and the repository that deploys it. Read it
instead of keeping a list of your own.

```php
use Dashcore\Bridge\Fleet\Catalogue;

Catalogue::installed(config('fleet.apps'));        // ['api', 'finance', 'ceo']
Catalogue::host('delivery', 'elitemcare.com');       // 'dcos.elitemcare.com'
Catalogue::app('coaching')['repo'];                  // 'dashcoretech/coaching.dashcore.com'
```

It reads no config: the domain and the installed-app list belong to the
install and are passed in. Grants are not here — the control plane owns and
applies them.

## Signing people in through the fleet

One account, one passkey, every app. People sign in at the fleet's sign-in
server (`login.<domain>`) and come back here with an `id_token` this app checks
against the keys it already holds from the manifest. There is no client secret:
this app proves it is itself with the same Ed25519 key it signs bridge calls
with.

Off until an install asks for it, and rolled one app at a time — flip one,
verify, flip the next:

```dotenv
BRIDGE_SSO_MODE=optional   # off | optional | required
BRIDGE_SSO_BREAK_GLASS=owner@example.com
```

- **`off`** — the app's own sign-in only. No sso routes are served.
- **`optional`** — adds exactly two routes, `/auth/sso/redirect` and
  `/auth/sso/callback`. Your own sign-in screen stays; link to
  `route('bridge.sso.redirect')` from it. (`BRIDGE_SSO=true`, from before modes,
  means this.)
- **`required`** — your sign-in page (the route named `login`) goes straight to
  the fleet's, and your own password is refused for everyone except the
  break-glass accounts. They reach the form at `/login?local`. A fleet sign-in
  that fails lands back on your page with the reason in
  `session('bridge.sso.error')` rather than looping.

**Break-glass is not optional.** `required` with nobody in
`BRIDGE_SSO_BREAK_GLASS` is served as `optional` and logged: the sign-in server
being down should stop new sign-ins, never lock the owner out. The list is
comma-separated values of your sign-in form's username field
(`BRIDGE_SSO_USERNAME`, default `email`).

The password is refused both where apps check it: at `Auth::attempt()` (Breeze,
Fortify's default) before the password is looked at, so the answer never says
whether it was right; and on a login made by your own authenticator (Fortify's
`authenticateUsing`) on the `login` / `login.store` routes, which is signed
straight back out. A login anywhere else — the sso callback, a remember-me
cookie — is left alone.

`GET /api/bridge/v1/ping` reports the mode actually served as `sso`, so the hub
can show every app's posture as the rollout moves.

The callback path is matched **exactly** at the sign-in server against the
address the manifest publishes for this app, so it is built from the manifest
here too. If you change `BRIDGE_SSO_CALLBACK_PATH`, it changes on both sides.

**Who the person is here.** By default they are matched on the sign-in
server's permanent handle for them (`sub`), falling back to a verified email
address the first time, after which the handle is written down:

```bash
php artisan vendor:publish --tag=bridge-sso-migration   # adds users.sso_subject
```

Without that column people are matched by verified email every time, which
works until somebody changes their address. An app with its own rules — an
invitation that must exist, a seat, a role — binds its own resolver instead:

```php
$this->app->bind(ResolvesSsoUser::class, OnlyEmployeesMaySignIn::class);
```

Returning `null` refuses the sign-in and the person is told they cannot open
this app. Provisioning is off by default (`BRIDGE_SSO_PROVISION`): in most apps
an account carries roles or a seat, and those are decisions rather than side
effects of somebody opening a URL.

**After a sign-in**, `Dashcore\Bridge\Events\SsoSignedIn` carries the user and
the claims, so an app can keep a name or a photo in step with the sign-in
server.

**What is checked before anyone is signed in:** the state this app issued, the
PKCE verifier it kept, the `iss` the response names, the id_token signature
against the manifest's keys for the provider (never the provider's own JWKS),
and the issuer, audience, expiry and nonce in its claims. A callback is good
for one arrival: the pending attempt is pulled from the session, so a replay
finds nothing waiting.

**Where an app stands:**

```bash
php artisan bridge:sso          # for a person
php artisan bridge:sso --json   # one line, for fleets
```

Reports the mode enforced and the mode configured (they differ when `required`
has no break-glass account), how many break-glass accounts there are (never
who), whether the `sso_subject` column exists, which resolver is bound,
whether it would create accounts, and the sign-in page a failure lands on.
Read-only. fleets runs it through each app's hosting to show the rollout.

## Security headers

Every app that installs the bridge sends the security headers `standards`
grades for, from one global middleware (`SetSecurityHeaders`):

| Header | Default | Notes |
|---|---|---|
| Content-Security-Policy | `frame-ancestors 'self'; base-uri 'self'; object-src 'none'` | Restricts framing, `<base>` and plugins; does not restrict scripts or styles. |
| Referrer-Policy | `strict-origin-when-cross-origin` | |
| Permissions-Policy | `camera=(self), microphone=(self), geolocation=(), usb=()` | Camera and mic stay open to the app itself; payment is left alone so Stripe's wallet buttons work. |
| Cross-Origin-Opener-Policy | `same-origin-allow-popups` | HTML pages only; still allows OAuth and payment popups. |
| Cross-Origin-Resource-Policy | `same-site` | HTML pages only, so images and files another site loads aren't blocked. |
| X-DNS-Prefetch-Control | `off` | |
| Strict-Transport-Security | `max-age=31536000` | HTTPS requests only. |

Cross-Origin-Embedder-Policy is not sent: it breaks third-party embeds, and
`standards` treats it as optional.

A header the app sets itself is never overridden. Change one per app with its
env variable (`BRIDGE_CSP`, `BRIDGE_HSTS`, `BRIDGE_REFERRER_POLICY`,
`BRIDGE_PERMISSIONS_POLICY`, `BRIDGE_COOP`, `BRIDGE_CORP`,
`BRIDGE_DNS_PREFETCH_CONTROL`), or turn them all off with
`BRIDGE_SECURITY_HEADERS=false`.

The bridge also serves `/.well-known/security.txt` (RFC 9116), with the
contact in `BRIDGE_SECURITY_CONTACT` (default `mailto:travis@dashcore.com`) and
an expiry a year out. An app's own static file takes precedence.

## Uploads

An app declares its largest upload in `BRIDGE_UPLOAD_MAX`: PHP shorthand
(`20M`) or bytes, or `none` when it takes no uploads. `standards` holds the
whole stack to that number in every environment. It does that by sending a
real file of that size through the web server, PHP and the framework, which
is what would have caught talent's CV upload before a person found it.

| Route | What it answers |
|---|---|
| `GET /api/bridge/v1/uploads` | The declared limit (`null` when unset, `0` for none) and PHP's own `upload_max_filesize`, `post_max_size`, `file_uploads` and `max_file_uploads`, in bytes. |
| `POST /api/bridge/v1/uploads/probe?bytes=&sha256=` | Receives a multipart `file` no larger than the declared limit. It answers `200` when the file arrived whole, or `422` with `stopped_by` naming the layer (`upload_max_filesize`, `body_dropped`, `altered`…). It keeps nothing: the file is discarded when the request ends. |

A refusal before PHP, such as a web server's 413, never reaches the app; the
caller reads that from the response it got back.

`Bridge::to($peer)->upload($path, $contents, $filename, $query)` sends an
upload. PHP never shows a multipart body to the receiver as raw bytes, so the
signature covers an empty body. Sign what identifies the file in the query,
as the probe does with its size and hash, and check the file against it on
arrival.

## Source of truth

This repository. `api.dashcore.com` used to vendor the package under
`packages/dashcore/bridge` and publish it here; it now installs from this
repository like every other app, so send changes here.
