<?php

namespace App\Building;

use App\Enums\BuildStatus;
use App\Enums\TestOutcome;
use App\Models\Build;
use App\Scanning\CheckoutLocator;
use App\Scanning\Evidence\ProbeReader;
use App\Scanning\SuiteRunner;
use App\Scanning\Workspace;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Installs the fleet baseline (dashcore/testkit) in one app on a branch and
 * proposes it as a PR — only once the baseline passes there and the full
 * suite has no new failures.
 *
 * The install records the app's current exceptions, so the PR is the moment
 * to review them: every route the app leaves public, every action without
 * loading feedback. If the app's own phpunit.xml does not run on Postgres,
 * the database check is switched off in the PR and flagged rather than
 * merged red.
 */
class TestkitInstaller
{
    /** What an install may change, and so what is committed. */
    private const FILES = ['packages/dashcore/testkit', 'composer.json', 'composer.lock', 'pint.json', 'config/testkit.php', 'tests/Feature/FleetBaselineTest.php'];

    public function __construct(
        private readonly CheckoutLocator $checkouts,
        private readonly SuiteRunner $runner,
        private readonly ProbeReader $reader,
        private readonly BuildVerifier $verifier,
        private readonly Publisher $publisher,
    ) {}

    public function run(Build $build): Build
    {
        $app = $build->fleetApp;
        $build->update(['status' => BuildStatus::Preparing, 'started_at' => now(), 'error' => null]);

        $workspace = null;
        $keepBranch = false;

        try {
            [$path, $version] = $this->release();

            $workspace = app(Workspace::class, ['app' => $app, 'for' => $build]);
            $revision = $workspace->resolveRevision();
            $branch = 'tests/testkit-'.$version.'-'.$build->id;

            $build->update(['base_ref' => $revision['ref'], 'base_sha' => $revision['sha'], 'branch' => $branch]);
            $workspace->prepare($revision['sha'], $branch);

            $installed = $this->installedVersion($workspace->path);

            if ($installed !== null && version_compare(ltrim($installed, 'v'), ltrim($version, 'v'), '>=')) {
                return $this->reject($build, ["{$app->name} already has dashcore/testkit {$installed}."]);
            }

            $build->update(['status' => BuildStatus::Writing]);
            $notes = $this->install($workspace, $path, $version, $installed === null);

            $build->update(['status' => BuildStatus::Verifying, 'notes' => $notes ?: null]);
            $reasons = $this->verify($build, $workspace);

            if ($reasons !== []) {
                return $this->reject($build, $reasons);
            }

            $files = $this->changedFiles($workspace);
            $build->update(['status' => BuildStatus::Publishing, 'changed_files' => $files]);

            $title = ($installed === null ? 'Add' : 'Upgrade').' the fleet baseline (dashcore/testkit '.$version.')';
            $published = $this->publisher->ship($build, $workspace, $files, $title, $title."\n\nBuild #{$build->id} from tests.dashcore.com: the baseline passes on this branch\nand the full suite has no new failures.\n\nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>", $this->body($build, $workspace, $version, $notes));

            $keepBranch = $published['pr_url'] === null;

            $build->update([
                'status' => $keepBranch ? BuildStatus::Ready : BuildStatus::Opened,
                'commit_sha' => $published['commit'],
                'pr_url' => $published['pr_url'],
                'pr_number' => $published['pr_number'],
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);

            $build->update(['status' => BuildStatus::Failed, 'error' => Str::limit($e->getMessage(), 5000), 'finished_at' => now()]);
        } finally {
            $workspace?->cleanup(keepBranch: $keepBranch);
        }

        return $build->refresh();
    }

    /**
     * The testkit checkout and the release to install from it.
     *
     * @return array{0: string, 1: string}
     */
    public function release(): array
    {
        $path = (string) (config('scanner.testkit.path') ?: (($root = $this->checkouts->root()) ? $root.'/testkit' : ''));

        if ($path === '' || ! is_file($path.'/bin/vendor-into')) {
            throw new RuntimeException('No dashcore/testkit checkout found. Set SCANNER_TESTKIT_PATH.');
        }

        $version = config('scanner.testkit.version');

        if (! $version) {
            Process::path($path)->timeout(120)->run(['git', 'fetch', '--quiet', '--tags', 'origin']);
            $version = trim(Process::path($path)->run(['git', 'tag', '--list', 'v*', '--sort=-v:refname'])->output());
            $version = strtok($version, "\n");
        }

        if (! $version) {
            throw new RuntimeException("The testkit checkout at {$path} has no release tags.");
        }

        return [$path, (string) $version];
    }

    public function installedVersion(string $root): ?string
    {
        $lock = json_decode((string) @file_get_contents($root.'/composer.lock'), true);

        foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
            if (($package['name'] ?? null) === 'dashcore/testkit') {
                return (string) ($package['version'] ?? '');
            }
        }

        return null;
    }

    /**
     * @return list<string> notes for the PR
     */
    private function install(Workspace $workspace, string $path, string $version, bool $fresh): array
    {
        $run = fn (array $command, string $what) => tap(
            Process::path($workspace->path)->timeout(1800)->env($workspace->environment())->run($command),
            fn ($result) => $result->failed() ? throw new RuntimeException($what.' failed: '.Str::limit(trim($result->errorOutput() ?: $result->output()), 2000)) : null,
        );

        $run([$path.'/bin/vendor-into', $workspace->path, $version], 'bin/vendor-into');
        $run([(string) config('scanner.composer'), 'require', '--dev', 'dashcore/testkit:^'.ltrim(Str::beforeLast($version, '.'), 'v'), '--no-interaction', '--no-progress'], 'composer require');

        $notes = [];

        if ($fresh) {
            $run([$this->runner->php(), 'artisan', 'testkit:install', '--no-interaction'], 'testkit:install');
            $notes = $this->databaseNote($workspace);
        }

        return $notes;
    }

    /**
     * The scan rewrote phpunit.xml to Postgres; what matters is what the app
     * itself commits. If that is not Postgres, the check would fail the day
     * the PR merges, so it is switched off here and flagged instead.
     *
     * @return list<string>
     */
    private function databaseNote(Workspace $workspace): array
    {
        $committed = '';

        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $file) {
            $show = Process::path($workspace->path)->run(['git', 'show', 'HEAD:'.$file]);

            if ($show->successful()) {
                $committed = $show->output();
                break;
            }
        }

        preg_match('/name="DB_CONNECTION"\s+value="([^"]*)"/', $committed, $m);
        $driver = $m[1] ?? 'unset';

        if ($driver === 'pgsql') {
            return [];
        }

        $config = $workspace->path.'/config/testkit.php';
        file_put_contents($config, (string) preg_replace(
            "/'driver' => 'pgsql',/",
            "// TODO: this suite runs on {$driver}; move phpunit.xml to a forced Postgres\n        // test database, then set this back to 'pgsql'.\n        'driver' => null,",
            (string) file_get_contents($config),
            1,
        ));

        return ["This suite's phpunit.xml uses {$driver}, not Postgres, so the database check is off in config/testkit.php until it moves."];
    }

    /**
     * @return list<string> reasons not to propose it
     */
    private function verify(Build $build, Workspace $workspace): array
    {
        $baseline = $this->runner->runFiles($workspace, ['tests/Feature/FleetBaselineTest.php'], 'baseline', 1800);
        $records = $this->reader->read($baseline['probe']);

        if ($records === []) {
            return ['The baseline test did not run: '.mb_substr(trim($baseline['output']), -1500)];
        }

        $reasons = [];

        foreach ($records as $record) {
            if ($record->outcome !== TestOutcome::Passed) {
                $reasons[] = "A baseline check does not pass ({$record->outcome->value}): {$record->name}".($record->message ? ' — '.mb_substr($record->message, 0, 500) : '');
            }
        }

        return $reasons === [] ? $this->verifier->suiteRegressions($build, $workspace) : $reasons;
    }

    /**
     * @return list<string>
     */
    private function changedFiles(Workspace $workspace): array
    {
        $status = Process::path($workspace->path)->run(['git', 'status', '--porcelain', '--untracked-files=all'])->output();
        $files = [];

        foreach (array_filter(explode("\n", $status)) as $line) {
            $path = substr($line, 3);

            foreach (self::FILES as $allowed) {
                if ($path === $allowed || str_starts_with($path, $allowed.'/')) {
                    $files[] = $path;
                }
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param  list<string>  $notes
     */
    private function body(Build $build, Workspace $workspace, string $version, array $notes): string
    {
        $config = @include $workspace->path.'/config/testkit.php';
        $list = fn (array $items) => $items === [] ? "_none_\n" : implode("\n", array_map(fn ($i) => '- `'.$i.'`', $items))."\n";

        $public = $list((array) ($config['routes']['public'] ?? []));
        $loading = $list((array) ($config['loading']['allow'] ?? []));
        $debug = $list((array) ($config['debug_calls']['allow'] ?? []));
        $env = $list((array) ($config['env_calls']['allow'] ?? []));
        $flag = $notes === [] ? '' : '> [!WARNING]'."\n> ".implode("\n> ", $notes)."\n\n";

        return <<<MD
            {$flag}Installs **dashcore/testkit {$version}**, the fleet baseline, in {$build->fleetApp->name}: route guards, the test database engine, migration round trips, debug and `env()` calls, and Livewire loading feedback.

            Each check is a ratchet. The exceptions this app has today are recorded in `config/testkit.php`, so the baseline passes now and fails only on something new. **Please review them** — each is either a deliberate decision or debt to work down.

            **Routes anyone can reach** (declared public)
            {$public}
            **Livewire actions without loading feedback**
            {$loading}
            **Debug calls**
            {$debug}
            **`env()` outside config**
            {$env}
            Verified by tests.dashcore.com before opening: the baseline passes on this branch and the full suite has no new failures. Build #{$build->id}, based on `{$this->short($build->base_sha)}`.

            🤖 Generated with [Claude Code](https://claude.com/claude-code)

            MD;
    }

    private function short(?string $sha): string
    {
        return substr((string) $sha, 0, 7);
    }

    /**
     * @param  list<string>  $reasons
     */
    private function reject(Build $build, array $reasons): Build
    {
        $build->update(['status' => BuildStatus::Rejected, 'error' => implode("\n", $reasons), 'finished_at' => now()]);

        return $build->refresh();
    }
}
