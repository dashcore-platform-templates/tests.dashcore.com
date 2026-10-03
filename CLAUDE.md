# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Development
composer run dev                    # Runs server + queue + pail logs + vite concurrently
npm run dev                         # Vite dev server only
npm run build                       # Production build

# Testing (Postgres database tests_dashcore_testing, forced in phpunit.xml; BCRYPT_ROUNDS=4)
php artisan test --compact                          # Run all tests
php artisan test --compact tests/Feature/           # Run a directory
php artisan test --compact --filter=ExampleTest     # Run by name

# Linting (run before finalizing changes)
vendor/bin/pint --dirty --format agent

# Artisan (always pass --no-interaction)
php artisan make:model ModelName --no-interaction
php artisan make:test TestName --pest --no-interaction
php artisan make:test TestName --pest --unit --no-interaction
php artisan migrate --no-interaction
```

## DashCore Operational Rules

1. **Data Safety:** Multi-step database logic MUST be wrapped in Transactions.
2. **Branding:** Use "DashCore" only. No "Elite" or other legacy terms.
3. **UI Standard:** Every Livewire action must have `wire:loading` feedback (no dead space).

## Architecture

**Stack:** Laravel 13 + Livewire 4.3 + Volt 1.7 + Tailwind CSS v4 + PostgreSQL

### Routing — No Controllers

Most routes render views directly with `Route::view()` or `Volt::route()`. There are almost no traditional controllers — pages are Volt single-file components containing both PHP logic and Blade templates. Auth routes use `Volt::route()` in `routes/auth.php`; app routes use `Route::view()` in `routes/web.php`.

### Volt Single-File Components

Auth pages and profile forms are Volt components in `resources/views/livewire/pages/auth/` and `resources/views/livewire/profile/`. Each file contains an anonymous PHP class at the top (with layout attribute, properties, methods) followed by the Blade template. VoltServiceProvider mounts both `resources/views/livewire` and `resources/views/pages` as Volt directories.

To test Volt components:
```php
Volt::test('pages.auth.login')
    ->set('form.email', $user->email)
    ->set('form.password', 'password')
    ->call('login')
    ->assertRedirect(route('dashboard'));
```

### Role System

`App\Enums\UserRole` is a backed string enum with three values: `owner`, `admin`, `member` (default). The `User` model casts the `role` column to this enum and provides `isOwner()`, `isAdmin()`, `isMember()` helper methods. Two middleware classes enforce access: `EnsureUserIsOwner` and `EnsureUserIsAdmin` (both abort 403 on failure). `UserFactory` has `->owner()` and `->admin()` state methods for tests.

### Layouts

- `<x-app-layout>` — Authenticated pages. Wraps content with the sidebar navigation. Uses `@persist('main-nav')` to avoid re-mounting navigation during Livewire SPA navigations.
- `<x-guest-layout>` — Public/auth pages. Centered, minimal, no sidebar.

### Theme System

CSS custom properties defined in `resources/css/app.css` with two modes applied via `body.theme-dark` / `body.theme-light`:

- **Surface tokens** (`surface-50` through `surface-950`) — backgrounds, text, borders
- **Accent tokens** (`accent-400/500/600`) — interactive elements (cyan/blue)
- **Status tokens** — `success-500`, `warning-500`
- **Card class** — `.card-os` for glassmorphism (backdrop-blur, semi-transparent bg)

Theme preference is stored in `localStorage('app-theme')` and applied before first paint via an inline script in the layout. The navigation component includes the toggle button.

### Livewire Patterns Used in This Codebase

- **Form objects** — `app/Livewire/Forms/LoginForm.php` extends `Livewire\Form` with `#[Validate]` attributes on properties
- **Invokable actions** — `app/Livewire/Actions/Logout.php` implements `__invoke()`, can be injected into any Volt component
- **Events** — Components dispatch events like `profile-updated` for Alpine.js listeners (e.g., `action-message` component shows success toasts)
- **SPA navigation** — Links use `wire:navigate` for instant page transitions

## What this app is: the fleet's test coverage plane

tests.dashcore.com scans every app in its fleet and records which of each app's
surfaces (routes, Livewire components/actions, and every public method under
`app/`) a passing, asserting test actually exercises.

- **Roster** — `App\Scanning\FleetSync` reads the `install` block of the control
  plane's signed manifest via `dashcore/bridge` (vendored in `packages/`). No app
  list is kept here. `php artisan tests:sync-fleet`.
- **Checkouts** — `SCANNER_ROOT` (e.g. `~/Herd`) holds one checkout per app,
  named by production host. `CheckoutLocator` refuses anything outside it.
- **Workspace** — each scan runs in a throwaway `git worktree` of the app's
  default branch (origin first), with a fresh `.env` from `.env.example` and
  phpunit.xml rewritten to force a scratch Postgres DB `tests_scan_{key}`. The
  app's working copy and dev database are never touched.
- **Probe** — `probe/` is loaded into the app's test process via
  `-d auto_prepend_file` + `--extension DashcoreTestsProbe\Extension`, with
  Xdebug loaded only for that process. It writes one JSON line per test: routes
  matched, Livewire mounts/calls, executed `app/` lines, outcome, assertions.
  It must never break a suite — keep every hook wrapped.
- **Verdicts** — `Inventory` lists surfaces statically (php-parser +
  `route:list --json --except-vendor`); `Analyzer` marks each Tested (passing
  test with ≥1 assertion reached it), Weak (only failing/risky/assertion-free
  tests did) or Missing.
- **Mutation** — after the suite, tested methods (incl. Volt class actions) get
  their body swapped for a trivial default (`Mutation\Mutator`) and their proving
  tests re-run; survivors become Weak (`VerdictReason::MutantSurvived`). Budgeted
  per scan; verdicts are reused while the method's body hash is unchanged.
- **Ratchet** — each scan diffs against the previous one (`SurfaceChange`):
  lost coverage and new-untested surfaces are regressions.
- **Builds** — `app/Building`: `BuildPlanner` picks a batch of open surfaces,
  `AgentRunner` runs Claude Code headless in a worktree branch (tests/ and
  database/factories/ only), `BuildVerifier` re-checks everything itself
  (reach, mutation, full suite), `Publisher` commits, pushes and opens a PR.
  `tests:build [keys] [--surface=] [--now]`; `tests:sync-builds` follows PRs.
- **Fleet baseline** — `dashcore/testkit` (repo `dashcoretech/testkit`, checkout
  `~/Herd/testkit`, vendored in `packages/`): route guards, Postgres, migration
  round trip, debug/env calls, Livewire loading feedback — ratchets recorded in
  `config/testkit.php`. `TestkitInstaller` rolls it out, one verified PR per app:
  `tests:install-testkit [keys] [--now]`. Scans record each app's version.
- **Running** — `tests:scan [keys] [--now]` queues `RunScan` on the `scans`
  connection (retry_after outlasts SCANNER_TIMEOUT). The schedule in
  `routes/console.php` syncs, scans nightly, follows build PRs and prunes old
  per-test detail, and (with `SCANNER_BUILD_NIGHTLY=true`) queues one build per
  app at 03:00. Two workers (queues `scans,builds`) are the launchd agents
  `com.dashcore.queue-tests` and `com.dashcore.queue-tests-2` in
  `~/Library/LaunchAgents` — never start one from the schedule: launchd kills a
  tick's background processes when it ends. Scratch space is
  `storage/scanner.noindex` (Spotlight must not index it). Big suites need
  `SCANNER_TIMEOUT=14400`.
- **Proving Ground** — `app/Simulation`, page `/proving-ground`: a never-ending
  simulation. Fake personas walk scripted `Journey`s (sign in, add a lead, win a
  deal…) through the real UI of the local `*.fakecompany.com` apps, whose data
  they are free to grow — never a real app. A journey is a `Script` of plain-
  language steps (`visit`, `fill`, `select`, `click`, `see`, `gone`);
  `simulator/run.mjs` (Node + Playwright, `npm install --prefix simulator`)
  walks it in headless Chromium and streams one JSON line per step, with a
  screenshot, which `Runner` writes back as it arrives. Targets, personas and
  journey weights live in `config/simulation.php`; the fake apps' seeders give
  every persona the password `password`. The page's switch only sets a flag:
  the worker is `tests:simulate` (`--once`, `--journey=`), kept alive by the
  launchd agent `com.dashcore.simulation-tests`, and like the queue workers it
  is never started from the schedule. It exits every `max_runs` so launchd
  restarts it on fresh code. Screenshots live in `storage/simulation.noindex`,
  kept for the newest `keep_runs` runs. After each run `Findings` turns failed
  steps, 5xx responses, browser errors, slow steps and clicks with no loading
  feedback (the runner watches `wire:loading` elements during the click's
  Livewire request) into findings, grouped by fingerprint; one is resolved
  after `resolve_after` clean runs that could have hit it, and reopened —
  flagged "came back" — if it returns. Journeys also work on what is already
  there: `remember()` keeps text off the page ("the first uncontacted lead")
  and later steps, labels and the summary use it as `{name}`. Each journey
  names the persona roles that do it (`roles()`, default `Sales rep`; the
  owner hands out leads and reviews the pipeline). The page's business pulse
  reads each target's own database (`database`, read only, in its
  `timezone`) through a `Pulse` class (`App\Simulation\Pulse`). Live
  coverage: the runner reports what each step reached — pages, Livewire calls
  (`name@method`, read from the update request's snapshot) and components on
  screen — `LiveCoverage` keeps a per-target tally (`simulation_reach`) and
  maps it onto the target's fleet app surfaces (`fleet_app`, default the
  target key); the fleet page marks those surfaces "live". Calls that map to
  no surface are listed as gaps in the scan's own inventory. The explorer
  (`tests:explore [target] [--focus=] [--now]`, or the page's Explore button;
  queued as `RunExploration` on the builds queue) runs Claude Code headless
  and read-only (Read/Glob/Grep, `--max-budget-usd`) over the target's
  `checkout`, aimed at the live coverage gaps, and drafts journeys as data
  (`simulation_journeys`, walked by `StoredJourney`; fake data as `[[tokens]]`).
  `DraftValidator` turns unsound drafts away; the rest go on trial (`Trials`):
  `trials.passes` passes in a row make one active, `trials.failures` failures
  hold it for review. Trial runs file no findings. Breaks are findings about a
  journey's step; dead space and slow steps are about an element on a page
  (page + action + selector), the same finding whichever journey hits it.

## NavigationRegistry Contract

Packages register navigation items via `App\Services\NavigationRegistry`, a singleton defined in `AppServiceProvider`. The navigation component (`resources/views/livewire/layout/navigation.blade.php`) reads from the registry — `items()` returns a Collection sorted by `order` then grouped by `group`. `hasItems()` is used by the dashboard to detect empty state.

### How packages register nav items

In a package's service provider:

```php
private function registerNavigation(): void
{
    if (! class_exists(\App\Services\NavigationRegistry::class)) {
        return;
    }

    $this->app->booted(function () {
        $registry = app(\App\Services\NavigationRegistry::class);
        $registry->register('Sales', 'Contacts', 'crm.contacts.index', 'users', 1);
    });
}
```

**Parameters:** `register(string $group, string $label, string $route, ?string $icon, int $order)`

- **group** — Dropdown group name in the sidebar (e.g., "Sales", "Finance", "HR")
- **label** — Display text for the nav item
- **route** — Named route (must exist — the nav checks with `Route::has()`)
- **icon** — Heroicon name (e.g., `users`, `chart-bar-square`, `currency-dollar`)
- **order** — Sort position within the group (lower = first)

The `class_exists` guard ensures the package doesn't break if installed in a non-DashCore Laravel app. The `booted()` callback defers registration until all providers have loaded.

## Customization Rules

### Never modify vendor

Files inside `vendor/` are managed by Composer. Never edit them directly. Instead:

- **Override views:** Publish package views with `php artisan vendor:publish --tag={slug}-views`, then edit the copies in `resources/views/vendor/{slug}/`
- **Override config:** Publish package config with `php artisan vendor:publish --tag={slug}-config`, then edit `config/{slug}.php`
- **Extend models:** Create an app-level model in `app/Models/` that extends the package model, then update the package config to point to your model (e.g., `'user_model' => \App\Models\CustomUser::class`)

### Where custom code lives

| What | Where | Notes |
|------|-------|-------|
| Custom pages | `app/Livewire/` + `resources/views/livewire/` | Standard Livewire components |
| Custom routes | `routes/web.php` | Packages own their own route files |
| Custom models | `app/Models/` | Extend package models if needed |
| Published views | `resources/views/vendor/{slug}/` | Owned by the app, not updated by package updates |
| Published config | `config/{slug}.php` | Review manually on package updates |

### Patterns to follow

- Use the `components.layouts.app` layout for authenticated pages (matches the navigation)
- Register custom nav items via `NavigationRegistry` in `AppServiceProvider::boot()` if needed
- Follow existing package patterns — Livewire components with `wire:loading`, database transactions for multi-step operations
- Use Tailwind utility classes with the existing theme tokens (`surface-*`, `accent-*`)

## When to Load Additional Context

- How to install packages → read `README.md` (Installing Packages section)
- Package architecture and structure → read the DashCore Portal's `PACKAGES.md`
- Customization protocol and package vs. custom decisions → read the DashCore Portal's `CUSTOMIZATION.md`
- General Laravel questions → use `search-docs` tool (Boost MCP)
