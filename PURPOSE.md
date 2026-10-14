# PURPOSE — tests.dashcore.com

**tests.dashcore.com is the fleet's test coverage plane: it scans every Dashcore app's own test suite and records which of that app's surfaces a passing, asserting test actually proves — for the owner and admins who decide what gets tested next.**

Every fleet app has its own suite, and every suite reports green about itself.
None of them can say what they leave untouched, and a suite that cannot start, or
one whose tests assert nothing, reads exactly as green as one that works. The
question "is this route, this Livewire action, this public method actually proven
by a test?" has to be asked from outside every app at once, with the same yardstick,
and remembered from one night to the next so that lost coverage shows up as a
regression. That is a job no single app can do about itself, so it is its own app.

## What it is for

People — the owner and admins (the `fleet-tests` permission) — use it to see,
per app, what is tested, what is only weakly tested and what is missing, and to
decide what the next batch of tests should cover. The jobs it does:

- **Scan.** Nightly, and on demand (`tests:scan`), each app's default branch is
  checked out into a throwaway git worktree with its own scratch Postgres
  database, and the app's suite runs with a probe loaded into the test process.
  The probe records, per test, the routes it reached, the Livewire components
  and actions it mounted or called, the `app/` lines it executed, its outcome
  and its assertions. The app's own working copy and development database are
  never touched.
- **Judge.** Every surface — routes, Livewire components and actions, every
  public method under `app/` — is listed statically and marked Tested (a passing
  test with at least one assertion reached it), Weak (only failing, risky or
  assertion-free tests did) or Missing. Tested methods are then mutated — their
  body swapped for a trivial default — and their proving tests re-run; a mutant
  that survives downgrades the surface to Weak.
- **Ratchet.** Each scan is diffed against the previous one, so lost coverage
  and new untested surfaces are named as regressions rather than averaged away.
- **Judge pull requests.** Open PRs across the fleet are scanned as GitHub would
  merge them, against a main scan of the exact commit they merge onto, and the
  verdict is posted to GitHub as the `tests/coverage` commit status. Making that
  status required in branch protection is the owner's decision and was still
  open on 2026-10-04 (`.claude/PROGRESS.md`).
- **Build tests.** For an app with gaps, the builder runs Claude Code headless
  in a worktree branch of that app, allowed to change only `tests/` and
  `database/factories/`. It re-verifies the result itself — reach, mutation,
  the full suite — and only then commits, pushes and opens a PR in the app's
  own repository, keeping at most one such PR per app waiting for review.
- **Roll out the baseline.** `dashcore/testkit` — the fleet's shared baseline
  checks (route guards, Postgres, migration round trip, stray debug and env
  calls, Livewire loading feedback) — is installed one verified PR per app.
- **Keep the test library.** `/library` is the home of every test of every app:
  each test file read straight from git at the scan's commit, its revisions,
  its outcome history and how many surfaces it proves.
- **Run the Proving Ground.** `/proving-ground` is a never-ending simulation:
  scripted personas walk journeys (sign in, add a lead, win a deal) through the
  real UI of local stand-in copies of fleet apps seeded with fake data, in a
  headless browser, and failed steps, server errors, slow steps and clicks with
  no loading feedback become findings. Coverage reached that way is mapped back
  onto the fleet page as "live".
- **Walk customer journeys.** `tests:journey` follows one customer across
  every app a stage of their life touches — enquiring on the website and
  arriving in sales first, then paying, being provisioned and onwards — in
  a stand-in fleet (a practice fleet, or the fake company). The list of
  stages, pending ones included, is the progress bar for the whole journey.

- **Workflows (optional, per install).** `/workflows` is where an owner or
  admin builds their own browser tests as records — a start URL, steps
  ("open /lets-talk", "type [[email]] into #df-email", "see Thank you"),
  how often, and who is emailed when one breaks and when it works again.
  Nothing there ships with the app or carries between installs: the fleet
  tests above are the standard every install runs the same way and change
  only when the fleet does; workflows are an empty list until someone needs
  one. The schedule walks the due ones itself (`tests:workflows`, every
  minute) in the same browser runner as the Proving Ground. Each tick also pings a
  Healthchecks.io-style service off this machine (`Heartbeat`), so the
  schedule going quiet — the Mac asleep, off or offline — is noticed from
  outside, and the pages warn when it has not ticked for five minutes.

A feature belongs here if it answers "does this app's code have proof behind
it, and is that proof getting better or worse?" across the fleet.

## What it owns

- The fleet's list of scannable apps as this app sees them (`fleet_apps`),
  reconciled from the hub's manifest — a cache of the roster, not a roster.
- Every scan, its per-surface verdicts, its per-test evidence, mutation results
  and the ratchet between scans.
- Pull request verdicts and the `tests/coverage` commit status they produce.
- Builds: the batches of surfaces chosen, the agent runs, their verification
  and the PRs they opened.
- The test library: every app's test files and their revisions, every test's
  outcome history and the surfaces it proves.
- The Proving Ground: journeys (scripted and drafted), runs, steps,
  screenshots, findings and live reach.

It does not own the tests themselves. Each test lives in its app's repository
and runs there; a PR this app opens is a proposal the app's reviewers accept or
close.

## What it is not for

- **Not the place a suite runs in CI.** Each app's own suite is that app's
  business; this app measures it from outside and reports a status.
- **Not outside-in checks of deployed apps.** Whether a live app meets the
  fleet's standards over HTTP is `standards`.
- **Not dependency or vulnerability auditing.** What the fleet runs that it
  should not is `security`.
- **Not uptime or production errors.** That is `health`.
- **Not the keeper of local checkouts.** Keeping `~/Herd` itself healthy — git,
  Composer, npm, databases — is `herd` (local only).
- **Not the fleet roster.** Which apps exist is the hub's manifest; this app
  reads it and keeps no list of its own.
- **Not a place to test against real data — except workflows, on purpose.**
  The Proving Ground and customer journeys drive only local stand-in apps
  (*.fakecompany.com, *.practice) with fake data, never a real app or a
  production install — and never one that has not shown its mail goes to its
  log. Workflows are the one exception, decided by the owner on 2026-10-13:
  they run against whatever site their record names, live ones included, and
  a form they send is a real submission there with everything that site does
  next. No guard stands in front of them; the person who builds one decides.
- **Not a writer of application code.** The builder may touch only tests and
  factories; anything else it changes is thrown away before verification.

## Where it sits in the fleet

Group: **Assurance**, beside `security` and `standards`. It is in the catalogue,
and since dashcore/bridge v0.11.30 (2026-10-13) one of the apps every install
must have again — it had been dropped in 0.11.24 for having no deployment, and
api's FleetPlan still lists it as not yet deployed. On 2026-10-04 it runs on the fleet host — a Mac under Herd, with launchd
keeping its queue workers and the simulation worker alive — because it needs
local checkouts of every app (`SCANNER_ROOT`), Herd's Xdebug, `gh` and Claude
Code. Treat it as not deployed until that changes.

Its one fleet read is the hub's signed manifest, through `dashcore/bridge`:
the `install` block is its roster, so a new app is scanned as soon as it is on
the roster and checked out locally. It holds no grant to any peer and serves no
read to one — no `FleetPlan` edge names it as caller or target — and it keeps
no peers in `config/services.php`. Its output reaches the fleet through GitHub
instead: commit statuses on pull requests and PRs into each app's repository.

It is human-facing (an admin panel) and it is cognition-adjacent tooling, not a
domain service: nothing in the fleet depends on it at request time, and if it
stops, the fleet keeps working and simply stops learning what its tests prove.

The live grants are `FleetPlan` in api.dashcore.com (`/admin/fleet/plan`).

## Rules that follow from its purpose

- **Never touch an app's working copy or database.** Every scan, build and PR
  scan runs in its own worktree against its own scratch database; a scan that
  would share a test database with another agent is a scan that lies.
- **A suite that does not finish is a failed scan, not a partial pass.** No
  report means no verdict.
- **A route counts as tested only when its action ran,** not when it matched.
- **Proposals, not pushes.** The builder opens a PR and waits; it never merges,
  and it keeps at most one PR per app in front of a person.
- **The probe must never break a suite.** It runs inside every app's test
  process; every hook stays wrapped.
- **Workflows never share the stand-in guard's path.** `StandInGuard` stays
  as strict as it is; a workflow is its own record, runner and schedule, and
  nothing a workflow allows loosens a journey.
- **Fake data only in the Proving Ground.** Any process started in a target's
  checkout gets the target's environment, never this app's, or its migrations
  run against this app's database.

---
*Last reviewed 2026-10-04 against `7e3c3be` of `main`.*
