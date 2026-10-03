# Progress

## Current State
_Updated: 2026-10-03 19:30 UTC · branch `main` (anchor `~/Herd/tests.dashcore.com`)_

**Building:** the fleet test coverage plane — last step is *enforcing* the PR ratchet (making the `tests/coverage` commit status required in branch protection).

**Verified complete:** all scanner/builder/PR-scan work is merged to `main` (PRs #1–#27; last `d706941`). All 22 fleet apps have a completed main scan (lowest: standards 59.4%, talent 60.0%, hr 64.4%, login 71.2%). All 22 testkit baseline PRs are merged (0 open). PR scanning is live: 3 launchd workers running (`com.dashcore.queue-tests`, `-2`, `-prs`); `.env` has `SCANNER_PR_SCANS=true`, `SCANNER_BUILD_NIGHTLY=true`, `SCANNER_GH`, `SCANNER_PATH`, `SCANNER_TIMEOUT=14400`. First PR verdict (standards #11, pass) checked against its diff — correct — and visible on GitHub. 415/416 tests pass.

**Next action:** fix the red test — add `<env name="SCANNER_GH" value="gh" force="true"/>` to `phpunit.xml` (the host's `.env` value leaks into `tests/Feature/Building/PublisherTest.php` › `test_only_the_verified_files_are_committed_pushed_and_proposed`). Then watch PR verdicts (Fleet page → app → Pull requests) until a **fail** verdict exists, verify it against the PR's diff, and propose branch protection requiring `tests/coverage` (user decides when).

**Blocked / red:** 1 red test (above, my regression from PR #26). Waiting on user: (1) when to require `tests/coverage`; (2) whether to PR email's `tests/Feature/Diagnostics/TestEnvironmentTest.php` to accept any isolated DB name (it asserts `email_dashcore_test`, so it always fails under scanner isolation).

**Dead ends — do not retry:** starting the scan worker from the schedule (launchd kills a tick's background processes — use the KeepAlive agents); fetching over SSH (port 22 to GitHub times out on this network — scans fetch `https://github.com/{repo}`); bare `gh`/`npm`/`claude` in background processes (launchd PATH has no Homebrew/nvm — use `SCANNER_GH`/`SCANNER_PATH`/`SCANNER_CLAUDE`); scratch under `storage/scanner` (Spotlight indexed it, load hit 70 — must be `storage/scanner.noindex`); counting a route as tested on match (only when its action ran); trusting partial suites (no JUnit = failed scan); a once-a-day builder schedule (missed 03:00 tick lost a day — hourly now); `pkill -f` on worktree paths (also kills other jobs' test runs).

**Decisions made:** PR scans compare against a main scan of the *exact* commit the PR merges onto (wait ≤3h, then latest main, noted) and never touch surfaces/ratchet; each scan/build/PR gets its own DB because many agents share apps' test DBs; builder keeps ≤1 PR per app awaiting review; testkit checks are ratchets (install records current exceptions; dcos migration-rollback debt recorded, not fixed); 2 scan workers (+1 PR worker) because the host's load is already ~12–16; mutation budget 40/scan, none on PR scans.

**Unverified:** no PR **fail** verdict seen yet; hourly builder (`:20`) not yet observed creating builds after PR #27; testkit version on scans (all scans predate the baseline merges, so most show none); the full overnight cycle (sync 00:30, scans 01:00) not observed since the fixes; the other session's Proving Ground / test library (`feature/lucky-river-*`) not reviewed — it logs `tests:library is not defined` from the anchor.

## Log
### 2026-10-03 19:30 — main
Built the whole plane in one long session: scanner + probe, mutation checks, ratchet, agent test builder, `dashcore/testkit` (repo `dashcoretech/testkit`, v0.1.6) rolled out fleet-wide, and PR scans with GitHub commit statuses (option A; option B = move the engine into testkit and run in GitHub Actions, deferred). Most of the cost was host-environment bugs found only on real runs: launchd PATH (npm, gh), SSH blocked, Spotlight load, set_time_limit in api's code killing coverage runs, coverage-only timing failures. Builder PRs reviewed and good: ceo #237, coaching #28 (both still open, awaiting review).
