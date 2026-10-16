<?php

namespace Dashcore\Bridge\Testing;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use PDO;

/**
 * One Postgres test database per git worktree.
 *
 * Every fleet app's phpunit.xml forces one test database name — say
 * `ceo_dashcore_test` — and forces it on purpose, so a shell that exported
 * another project's .env cannot aim the suite at the wrong database. But the
 * file is the same in every worktree, so every worktree of a repo shared that
 * one database. While suites ran on in-memory SQLite that never mattered;
 * once they moved to Postgres, two sessions testing the same repo at once
 * deadlocked each other (15 October 2026: three times in one afternoon).
 *
 * In a linked worktree the name gains the worktree's suffix —
 * `ceo_dashcore_test__wt_nimble_river` — and the database is created if it is
 * missing. Nothing changes in a main checkout, in CI, off Postgres, or with
 * BRIDGE_SHARED_TEST_DB=true. The name comes from the checkout's folder, not a
 * file, so every install still runs the same code. herd-wt.sh's teardown drops
 * these databases with the worktree.
 */
final class WorktreeTestDatabase
{
    /** Postgres truncates identifiers past this. */
    private const MAX_NAME = 63;

    public const MARKER = '__wt_';

    /**
     * The worktree's own name for a test database, or null when the checkout
     * is not a linked worktree.
     */
    public static function nameFor(string $base, string $checkout): ?string
    {
        $suffix = self::worktreeSuffix($checkout);

        if ($suffix === null || str_contains($base, self::MARKER)) {
            return null;
        }

        $name = $base.self::MARKER.$suffix;

        return strlen($name) <= self::MAX_NAME
            ? $name
            : substr($name, 0, self::MAX_NAME - 9).'_'.substr(md5($name), 0, 8);
    }

    /**
     * Point the default Postgres connection at this worktree's test database.
     *
     * @param  (Closure(array<string, mixed>, string): void)|null  $create  makes the database; the default connects to `postgres` and creates it
     */
    public static function apply(Application $app, ?Closure $create = null): void
    {
        if (! $app->runningUnitTests() || self::inCi() || filter_var(getenv('BRIDGE_SHARED_TEST_DB') ?: false, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $config = $app['config'];
        $connection = (string) $config->get('database.default');
        $settings = (array) $config->get("database.connections.{$connection}", []);

        if (($settings['driver'] ?? null) !== 'pgsql' || ! is_string($settings['database'] ?? null)) {
            return;
        }

        $name = self::nameFor($settings['database'], $app->basePath());

        if ($name === null) {
            return;
        }

        ($create ?? self::createIfMissing(...))($settings, $name);

        $config->set("database.connections.{$connection}.database", $name);
    }

    /** The worktree's task name, slugged; null for a main checkout or no git. */
    private static function worktreeSuffix(string $checkout): ?string
    {
        // A linked worktree's .git is a file naming its gitdir:
        // `gitdir: <anchor>/.git/worktrees/<name>`. A main checkout's is a folder.
        $marker = rtrim($checkout, '/').'/.git';

        if (! is_file($marker)) {
            return null;
        }

        if (! preg_match('/^gitdir:\s*(.+)$/m', (string) file_get_contents($marker), $m)) {
            return null;
        }

        $gitdir = rtrim(trim($m[1]), '/');

        if (! str_contains($gitdir, '/.git/worktrees/')) {
            return null;
        }

        $anchor = basename(dirname($gitdir, 3));
        $folder = basename(rtrim($checkout, '/'));

        // ceo.dashcore.com-nimble-river under ceo.dashcore.com → nimble-river;
        // otherwise the worktree's own name in git.
        $task = str_starts_with($folder, $anchor.'-') ? substr($folder, strlen($anchor) + 1) : basename($gitdir);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($task)), '_');

        return $slug === '' ? null : $slug;
    }

    private static function inCi(): bool
    {
        return filter_var(getenv('CI') ?: false, FILTER_VALIDATE_BOOL)
            || filter_var(getenv('GITHUB_ACTIONS') ?: false, FILTER_VALIDATE_BOOL);
    }

    /** @param  array<string, mixed>  $settings */
    private static function createIfMissing(array $settings, string $name): void
    {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $settings['host'] ?? '127.0.0.1', $settings['port'] ?? 5432);
        $pdo = new PDO($dsn, $settings['username'] ?? null, $settings['password'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $exists->execute([$name]);

        if ($exists->fetchColumn() === false) {
            $pdo->exec('CREATE DATABASE "'.str_replace('"', '""', $name).'"');
        }
    }
}
