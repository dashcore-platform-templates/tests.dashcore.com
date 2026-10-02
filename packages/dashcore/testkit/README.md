# dashcore/testkit

The DashCore fleet's baseline tests. Installed in every fleet app, it adds one
test file whose checks are **ratchets**: what an app has today is recorded as
its allowed exceptions in `config/testkit.php`, so the test passes on the day
it is installed and fails only on something new.

| Check | Fails when |
|---|---|
| Route guards | an app route has no authentication guard and is not declared public in `testkit.routes.public` |
| Test database | the suite does not run on the production engine (Postgres) |
| Migrations | a migration's `down()` does not undo its `up()` exactly (full up → down → up round trip) |
| Debug calls | `dd()`, `dump()`, `ray()`, `var_dump()`… are left in app code |
| `env()` | `env()` is read outside `config/` (it returns null once config is cached) |
| Loading feedback | a Livewire `wire:click`/`wire:submit`/`wire:change` action has no `wire:loading` feedback — DashCore's "no dead space" rule |

Volt and Livewire full-page routes count as the app's own routes, so pages
like `login` are checked too.

## Installing in a fleet app

The repository is private, so an app carries the release it uses, the same way
it carries `dashcore/bridge`:

```bash
bin/vendor-into ~/Herd/some-app            # newest release
composer require --dev dashcore/testkit:^0.1 --no-interaction   # in the app
php artisan testkit:install                # in the app
```

`testkit:install` writes `tests/Feature/FleetBaselineTest.php` (Pest or
PHPUnit, matching the suite), publishes `config/testkit.php`, and records the
app's current exceptions. Review the recorded lists: each entry is either a
deliberate decision (a public webhook) or debt to work down.

`php artisan testkit:baseline` re-records the exceptions. Run it only on
purpose — it accepts whatever crept in since the last baseline.

tests.dashcore.com installs it fleet-wide with `php artisan tests:install-testkit`,
one PR per app.
