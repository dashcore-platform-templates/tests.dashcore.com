<?php

namespace Tests\Feature\Scanning;

use App\Models\FleetApp;
use App\Models\Scan;
use App\Scanning\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\Concerns\UsesFleetFixtures;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase, UsesFleetFixtures;

    private string $repo;

    private FleetApp $fleetApp;

    private Scan $scan;

    protected function setUp(): void
    {
        parent::setUp();

        $root = $this->scannerRootWith(['demo.dashcore.com'], git: false);
        $this->repo = $root.'/demo.dashcore.com';

        // CREATE DATABASE cannot run inside RefreshDatabase's transaction, so
        // the scratch database is managed over a connection of its own.
        config(['database.connections.scanner_admin' => config('database.connections.pgsql')]);
        config([
            'scanner.database.connection' => 'scanner_admin',
            'scanner.database.prefix' => 'tests_scan_unit_',
            'scanner.workspace' => $root.'/_workspace',
            'scanner.composer' => 'true',
        ]);

        $this->git('init', '--quiet', '--initial-branch=main');
        file_put_contents($this->repo.'/.env.example', "APP_NAME=Demo\nAPP_KEY=\nDB_CONNECTION=sqlite\nRESEND_API_KEY=\n");
        file_put_contents($this->repo.'/.env', "APP_KEY=base64:LIVEKEY\nRESEND_API_KEY=re_live_secret\n");
        file_put_contents($this->repo.'/.gitignore', ".env\n");
        file_put_contents($this->repo.'/phpunit.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit>
                <php>
                    <env name="APP_ENV" value="testing"/>
                    <env name="DB_CONNECTION" value="sqlite"/>
                    <env name="DB_DATABASE" value="demo_test" force="true"/>
                    <server name="DB_DATABASE" value="demo_test" force="true"/>
                </php>
            </phpunit>
            XML);
        $this->git('add', '.');
        $this->git('-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '--quiet', '-m', 'Initial');

        $this->fleetApp = FleetApp::factory()->create(['key' => 'demo', 'host' => 'demo.dashcore.com', 'local_path' => $this->repo]);
        $this->scan = $this->fleetApp->scans()->create(['status' => 'queued']);
    }

    protected function tearDown(): void
    {
        DB::connection('scanner_admin')->statement('DROP DATABASE IF EXISTS "tests_scan_unit_demo" WITH (FORCE)');
        DB::purge('scanner_admin');

        parent::tearDown();
    }

    private function git(string ...$args): string
    {
        return Process::path($this->repo)->run(['git', ...$args])->throw()->output();
    }

    private function databaseExists(): bool
    {
        return DB::connection('scanner_admin')->table('pg_database')->where('datname', 'tests_scan_unit_demo')->exists();
    }

    public function test_the_default_branch_is_resolved_and_a_failed_fetch_is_noted(): void
    {
        $workspace = new Workspace($this->fleetApp, $this->scan);

        $revision = $workspace->resolveRevision();

        $this->assertSame('main', $revision['ref']);
        $this->assertSame(trim($this->git('rev-parse', 'HEAD')), $revision['sha']);
        $this->assertSame('Initial', $revision['subject']);
        $this->assertStringContainsString('git fetch failed', $workspace->notes[0]);
    }

    public function test_the_scan_runs_on_the_committed_code_with_its_own_env_and_database_and_leaves_nothing_behind(): void
    {
        $workspace = new Workspace($this->fleetApp, $this->scan);
        $sha = $workspace->resolveRevision()['sha'];

        // Someone is mid-change in the real checkout.
        file_put_contents($this->repo.'/phpunit.xml', '<phpunit><!-- half edited');

        $workspace->prepare($sha);

        try {
            $this->assertDirectoryExists($workspace->path);
            $this->assertStringNotContainsString($this->repo, $workspace->path, 'never the live checkout');
            $this->assertTrue($this->databaseExists());

            $env = file_get_contents($workspace->path.'/.env');
            $this->assertStringContainsString('APP_NAME=Demo', $env);
            $this->assertMatchesRegularExpression('/^APP_KEY=base64:\S{40,}$/m', $env);
            $this->assertStringContainsString('DB_DATABASE=tests_scan_unit_demo', $env);
            $this->assertStringContainsString('DB_CONNECTION=pgsql', $env);
            $this->assertStringNotContainsString('re_live_secret', $env, 'the live .env never reaches a scan');
            $this->assertStringNotContainsString('LIVEKEY', $env);
            $this->assertStringNotContainsString('PATH=', $env, 'process settings stay out of the app\'s .env');

            $xml = simplexml_load_file($workspace->path.'/phpunit.xml');
            $this->assertNotFalse($xml, 'the committed phpunit.xml is used, not the half-edited one');
            $entries = fn (string $tag, string $name) => $xml->xpath("/phpunit/php/{$tag}[@name='{$name}']");

            $this->assertCount(1, $entries('env', 'DB_DATABASE'));
            $this->assertSame('tests_scan_unit_demo', (string) $entries('env', 'DB_DATABASE')[0]['value']);
            $this->assertSame('true', (string) $entries('env', 'DB_DATABASE')[0]['force']);
            $this->assertSame('tests_scan_unit_demo', (string) $entries('server', 'DB_DATABASE')[0]['value']);
            $this->assertSame('pgsql', (string) $entries('env', 'DB_CONNECTION')[0]['value']);
            $this->assertSame('testing', (string) $entries('env', 'APP_ENV')[0]['value'], 'other entries are kept');

            $this->assertStringContainsString($workspace->path, $this->git('worktree', 'list'));
        } finally {
            $workspace->cleanup();
        }

        $this->assertDirectoryDoesNotExist($workspace->path);
        $this->assertStringNotContainsString($workspace->path, $this->git('worktree', 'list'));
        $this->assertFalse($this->databaseExists());
        $this->assertSame('<phpunit><!-- half edited', file_get_contents($this->repo.'/phpunit.xml'), 'the live checkout is untouched');
    }

    public function test_a_pull_request_resolves_to_its_merge_and_the_main_commit_it_merges_onto(): void
    {
        $origin = $this->scannerRoot.'/origin.git';
        Process::run(['git', 'init', '--quiet', '--bare', $origin])->throw();
        $this->git('remote', 'add', 'origin', $origin);
        $this->git('push', '--quiet', 'origin', 'main');
        $main = trim($this->git('rev-parse', 'HEAD'));

        // GitHub's merge ref: main merged with the PR's head.
        $this->git('checkout', '--quiet', '-b', 'feature');
        file_put_contents($this->repo.'/feature.txt', 'x');
        $this->git('add', 'feature.txt');
        $this->git('-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '--quiet', '-m', 'Add exports');
        $this->git('checkout', '--quiet', 'main');
        $this->git('-c', 'user.email=t@t', '-c', 'user.name=t', 'merge', '--quiet', '--no-ff', '--no-edit', 'feature');
        $this->git('push', '--quiet', 'origin', 'HEAD:refs/pull/7/merge');
        $merge = trim($this->git('rev-parse', 'HEAD'));
        $this->git('reset', '--quiet', '--hard', $main);

        $scan = $this->fleetApp->scans()->create(['kind' => 'pull_request', 'status' => 'queued', 'pr_number' => 7]);
        $revision = (new Workspace($this->fleetApp, $scan))->resolvePullRequest(7);

        $this->assertSame($merge, $revision['sha']);
        $this->assertSame($main, $revision['base'], 'compared with the main it merges onto');
        $this->assertSame('Add exports', $revision['subject']);
        $this->assertSame('tests_scan_unit_demo_pr7', (new Workspace($this->fleetApp, $scan))->database);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pull request #8 has no merge ref');
        (new Workspace($this->fleetApp, $scan))->resolvePullRequest(8);
    }

    public function test_configured_directories_go_in_front_of_path(): void
    {
        config(['scanner.path' => '/opt/node/bin']);

        $this->assertStringStartsWith('/opt/node/bin:', (new Workspace($this->fleetApp, $this->scan))->environment()['PATH']);
    }

    public function test_a_build_gets_its_own_branch_directory_and_database(): void
    {
        $build = $this->fleetApp->builds()->create(['status' => 'queued', 'targets' => []]);
        $workspace = new Workspace($this->fleetApp, $build);

        $this->assertStringContainsString('/worktrees/build-'.$build->id, $workspace->path);
        $this->assertSame('tests_scan_unit_demo_build', $workspace->database);

        $workspace->prepare($workspace->resolveRevision()['sha'], 'tests/auto-1');

        try {
            $this->assertSame('tests/auto-1', trim(Process::path($workspace->path)->run(['git', 'branch', '--show-current'])->output()));
            $this->assertArrayHasKey('phpunit.xml', $workspace->rewritten);
            $this->assertArrayHasKey('.env', $workspace->rewritten);
        } finally {
            $workspace->cleanup();
            DB::connection('scanner_admin')->statement('DROP DATABASE IF EXISTS "tests_scan_unit_demo_build" WITH (FORCE)');
        }

        $this->assertStringNotContainsString('tests/auto-1', $this->git('branch', '--list'), 'the branch goes with the worktree');
    }

    public function test_an_app_without_a_phpunit_config_cannot_be_scanned(): void
    {
        $this->git('rm', '--quiet', 'phpunit.xml');
        $this->git('-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '--quiet', '-m', 'Drop phpunit');

        $workspace = new Workspace($this->fleetApp, $this->scan);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('no phpunit.xml');

            $workspace->prepare($workspace->resolveRevision()['sha']);
        } finally {
            $workspace->cleanup();
            $this->assertDirectoryDoesNotExist($workspace->path);
        }
    }

    public function test_assets_that_cannot_be_built_stop_the_scan_rather_than_fail_every_page_test(): void
    {
        file_put_contents($this->repo.'/package.json', '{"scripts": {"build": "vite build"}}');
        $this->git('add', 'package.json');
        $this->git('-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '--quiet', '-m', 'Add assets');
        File::ensureDirectoryExists($this->repo.'/node_modules');
        config(['scanner.npm' => 'npm-that-is-not-on-path']);

        $workspace = new Workspace($this->fleetApp, $this->scan);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('npm run build failed');

            $workspace->prepare($workspace->resolveRevision()['sha']);
        } finally {
            $workspace->cleanup();
        }
    }

    public function test_a_composer_install_that_fails_stops_the_scan(): void
    {
        config(['scanner.composer' => 'false']);
        $workspace = new Workspace($this->fleetApp, $this->scan);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('composer install failed');

            $workspace->prepare($workspace->resolveRevision()['sha']);
        } finally {
            $workspace->cleanup();
        }
    }
}
