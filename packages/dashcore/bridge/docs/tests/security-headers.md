# Tests: security headers and security.txt

**Why:** `standards`, the fleet's outside-in auditor, ran for the first time on
2026-10-07. Every one of 21 apps was missing every security header it grades,
plus `/.well-known/security.txt`: about 190 of its 279 failures. Every app
installs the bridge, so the bridge sends them, instead of 21 apps each adding
their own. The owner chose the security.txt contact (travis@dashcore.com) and
decided COEP should stay optional.

**What changed:**
- A global `SetSecurityHeaders` middleware.
- `bridge.security_headers` config, each header overridable by env.
- A `/.well-known/security.txt` route.

The values are chosen not to break anything the fleet already does: see the
README's "Security headers" section.

## Automated tests: `tests/Feature/SecurityHeadersTest.php`

Run with `vendor/bin/pest tests/Feature/SecurityHeadersTest.php`.

| Test | Proves |
|---|---|
| sends the security headers on an HTML page | Every graded header is sent with its default value. |
| never sends a Cross-Origin-Embedder-Policy | COEP stays off (owner's decision; it breaks third-party embeds). |
| sends a CSP that standards grades as strict | The CSP has no `unsafe-inline`, `unsafe-eval` or bare `*`, so standards' `csp-strict` check passes too. |
| sends HSTS over HTTPS only | A local `.test` site is never pinned to HTTPS. |
| keeps the cross-origin pair off anything that is not an HTML page | An image or JSON response doesn't get CORP/COOP, so other sites can still load app-served files. |
| leaves payment alone (v0.11.26) | Permissions-Policy has no `payment` directive, so Stripe Elements' wallet buttons (dcos) work. |
| never overrides a header the app set itself | An app's own stricter policy wins. |
| covers error pages too | A 404 carries the headers. |
| lets an app change one header, or drop it, by config | Per-app override and per-header opt-out. |
| sends nothing when switched off | `BRIDGE_SECURITY_HEADERS=false` disables them all. |
| publishes security.txt with the owner's contact | RFC 9116 file with Contact, an Expires a year out, and Canonical. |

Six of these fail if the middleware isn't registered (checked by removing it).
Full bridge suite: 191 passed.
