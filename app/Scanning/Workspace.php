<?php

namespace App\Scanning;

use App\Models\Build;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Scanning\Exceptions\GitHubUnreachable;
use App\Scanning\Exceptions\PullRequestUnmergeable;
use App\Services\FleetExport;
use App\Services\Install;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Connection;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A disposable copy of one app to run its suite in.
 *
 * The scan never runs in the app's own working copy — someone may be halfway
 * through a change there — and never against its development database. It
 * checks the default branch out into a git worktree, gives it a fresh .env
 * from .env.example (so no real credential is in reach of a test), points
 * phpunit.xml at a Postgres database of its own, and removes all of it after.
 */
class Workspace
{
    public string $path;

    public string $runDir;

    public string $database;

    /** @var list<string> */
    public array $notes = [];

    /** The branch the worktree was created on, for a build; null for a scan. */
    public ?string $branch = null;

    /** Hashes of the files this workspace rewrote, so they are not mistaken for an agent's changes. */
    public array $rewritten = [];

    private bool $worktreeAdded = false;

    private bool $databaseCreated = false;

    /**
     * A scan and a build of the same app can run at once, so each gets its own
     * worktree directory and its own database — and every fleet's tests runs
     * on the same Mac, so both carry this install's name too (Install::scoped).
     */
    public function __construct(
        private readonly FleetApp $app,
        Scan|Build $for,
    ) {
        $kind = $for instanceof Build ? 'build' : 'scan';
        $base = rtrim((string) config('scanner.workspace'), '/').'/'.Install::scoped($app->key);

        $this->path = $base.'/worktrees/'.$kind.'-'.$for->id;
        $this->runDir = $base.'/'.($kind === 'build' ? 'builds' : 'runs').'/'.$for->id;
        $suffix = match (true) {
            $for instanceof Build => '_build',
            $for->isPullRequest() => '_pr'.$for->pr_number,
            default => '',
        };

        $this->database = Str::of((string) config('scanner.database.prefix'))
            ->append(Install::scoped($app->key), $suffix)
            ->lower()
            ->replaceMatches('/[^a-z0-9_]/', '_')
            ->limit(63, '')
            ->toString();
    }

    /**
     * @return array{ref: string, sha: string, subject: string}
     */
    public function resolveRevision(): array
    {
        if (! Install::isHome()) {
            return $this->resolveLiveRelease();
        }

        $repo = (string) $this->app->local_path;

        $fetch = Process::path($repo)->timeout(120)->run(['git', 'fetch', '--quiet', $this->remote(), '+refs/heads/*:refs/remotes/origin/*']);

        if ($fetch->failed()) {
            $this->notes[] = 'git fetch failed, so the scan used the last fetched state: '.trim($fetch->errorOutput());
        }

        foreach ($this->candidateRefs($repo) as $ref) {
            $sha = Process::path($repo)->run(['git', 'rev-parse', '--verify', '--quiet', $ref.'^{commit}']);

            if ($sha->successful() && trim($sha->output()) !== '') {
                $sha = trim($sha->output());
                $subject = trim(Process::path($repo)->run(['git', 'log', '-1', '--format=%s', $sha])->output());

                return ['ref' => $ref, 'sha' => $sha, 'subject' => $subject];
            }
        }

        throw new RuntimeException('No default branch could be resolved in '.$repo.'.');
    }

    /**
     * A client or practice fleet is scanned at the release it runs, never at
     * origin's default branch: its coverage is the coverage of what its
     * people use. fleets says which release that is; tags come from the
     * app's repository (fetched, never pushed to).
     *
     * @return array{ref: string, sha: string, subject: string}
     */
    private function resolveLiveRelease(): array
    {
        $repo = (string) $this->app->local_path;
        $live = app(FleetExport::class)->live(Install::fleet(), $this->app->key);

        if ($live === null || ($live['release'] === null && $live['commit'] === null)) {
            throw new RuntimeException("fleets does not say which release {$this->app->key} runs in the ".Install::fleet().' fleet, so it was not scanned: a fleet other than Dashcore\'s is scanned at the release it runs, never at its default branch.');
        }

        $fetch = Process::path($repo)->timeout(120)->run(['git', 'fetch', '--quiet', $this->remote(), '+refs/tags/*:refs/tags/*']);

        if ($fetch->failed()) {
            $this->notes[] = 'git fetch failed, so the scan used the tags already in the checkout: '.trim($fetch->errorOutput());
        }

        $candidates = array_filter([
            $live['commit'],
            $live['release'] === null ? null : 'refs/tags/'.$live['release'],
        ]);

        foreach ($candidates as $ref) {
            $sha = Process::path($repo)->run(['git', 'rev-parse', '--verify', '--quiet', $ref.'^{commit}']);

            if ($sha->successful() && trim($sha->output()) !== '') {
                $sha = trim($sha->output());
                $subject = trim(Process::path($repo)->run(['git', 'log', '-1', '--format=%s', $sha])->output());

                return ['ref' => $live['release'] ?? $sha, 'sha' => $sha, 'subject' => $subject];
            }
        }

        throw new RuntimeException("{$this->app->key} runs ".($live['release'] ?? $live['commit']).' in the '.Install::fleet().' fleet, and neither that release nor its commit is in '.$repo.'.');
    }

    /**
     * Check $sha out into the worktree — detached for a scan, on a new $branch
     * for a build — and make it ready to run its suite.
     */
    public function prepare(string $sha, ?string $branch = null): void
    {
        $repo = (string) $this->app->local_path;

        File::ensureDirectoryExists(dirname($this->path));
        File::ensureDirectoryExists($this->runDir);

        if (is_dir($this->path)) {
            $this->removeWorktree();
        }

        $this->mustRun(
            Process::path($repo)->timeout(300),
            $branch === null
                ? ['git', 'worktree', 'add', '--detach', $this->path, $sha]
                : ['git', 'worktree', 'add', '-b', $branch, $this->path, $sha],
            'git worktree add',
        );
        $this->branch = $branch;
        $this->worktreeAdded = true;
        $this->path = (string) realpath($this->path);

        $this->writeEnv();
        $this->installDependencies($repo);
        $this->createDatabase();
        $this->pointPhpunitAtDatabase();
    }

    /**
     * Remove the worktree and the scratch database. A build's branch is
     * deleted with it unless $keepBranch — once pushed, the remote holds it.
     */
    public function cleanup(bool $keepBranch = false): void
    {
        if ($this->worktreeAdded) {
            $this->removeWorktree();
        }

        if ($this->branch !== null && ! $keepBranch) {
            Process::path((string) $this->app->local_path)->run(['git', 'branch', '-D', $this->branch]);
        }

        if ($this->databaseCreated) {
            try {
                $this->connection()->statement('DROP DATABASE IF EXISTS "'.$this->database.'" WITH (FORCE)');
            } catch (\Throwable $e) {
                $this->notes[] = 'The scratch database could not be dropped: '.$e->getMessage();
            }
        }
    }

    /**
     * The environment every process in the worktree runs with. Process
     * environment wins over a non-forced phpunit.xml entry and over .env, so
     * the database it names is the only one in reach.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        $config = $this->connection()->getConfig();

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) ($config['host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($config['port'] ?? '5432'),
            'DB_DATABASE' => $this->database,
            'DB_USERNAME' => (string) ($config['username'] ?? ''),
            'DB_PASSWORD' => (string) ($config['password'] ?? ''),
            'DB_URL' => '',
            'MAIL_MAILER' => 'array',
            'NIGHTWATCH_ENABLED' => 'false',
            'XDEBUG_MODE' => 'coverage',
            'PATH' => $this->path(),
        ];
    }

    private function path(): string
    {
        $extra = trim((string) config('scanner.path'), ': ');
        $current = (string) getenv('PATH');

        return $extra === '' ? $current : $extra.':'.$current;
    }

    /**
     * A pull request as GitHub would merge it: its merge ref, and the base
     * commit that merge sits on — the main commit it has to be compared with.
     *
     * @return array{ref: string, sha: string, subject: string, base: string}
     */
    public function resolvePullRequest(int $number): array
    {
        $repo = (string) $this->app->local_path;
        $local = 'refs/remotes/origin/tests-pull/'.$number;

        $fetch = Process::path($repo)->timeout(180)->run(['git', 'fetch', '--quiet', '--force', $this->remote(), "+refs/pull/{$number}/merge:{$local}"]);

        if ($fetch->failed()) {
            $error = trim($fetch->errorOutput());

            if (str_contains($error, "couldn't find remote ref")) {
                throw new PullRequestUnmergeable("Pull request #{$number} has no merge ref: it conflicts with its base, or it was closed.");
            }

            throw new GitHubUnreachable("GitHub could not be reached to fetch pull request #{$number}: ".Str::limit($error, 300));
        }

        $sha = trim(Process::path($repo)->run(['git', 'rev-parse', '--verify', $local.'^{commit}'])->output());
        $base = trim(Process::path($repo)->run(['git', 'rev-parse', '--verify', $sha.'^1'])->output());
        $subject = trim(Process::path($repo)->run(['git', 'log', '-1', '--format=%s', $sha.'^2'])->output());

        if ($sha === '' || $base === '') {
            throw new RuntimeException("Pull request #{$number}'s merge ref could not be read.");
        }

        return ['ref' => "pull/{$number}/merge", 'sha' => $sha, 'subject' => $subject, 'base' => $base];
    }

    /**
     * Where to fetch from: the app's GitHub repository over HTTPS when it is
     * known, whatever protocol the checkout's own origin uses — SSH to GitHub
     * is not reachable from every network the host sits on.
     */
    public function remote(): string
    {
        $repo = $this->app->repo;

        return $repo === null || $repo === ''
            ? 'origin'
            : rtrim((string) config('scanner.git_base'), '/').'/'.$repo.'.git';
    }

    /**
     * @return list<string>
     */
    private function candidateRefs(string $repo): array
    {
        $refs = [];
        $head = Process::path($repo)->run(['git', 'symbolic-ref', '--quiet', '--short', 'refs/remotes/origin/HEAD']);

        if ($head->successful() && trim($head->output()) !== '') {
            $refs[] = trim($head->output());
        }

        return array_values(array_unique([...$refs, 'origin/main', 'origin/master', 'main', 'master']));
    }

    private function writeEnv(): void
    {
        $example = $this->path.'/.env.example';
        $env = is_file($example) ? (string) file_get_contents($example) : '';

        // Process-level settings stay out of the app's own .env.
        $values = [
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            ...array_diff_key($this->environment(), array_flip(['PATH', 'XDEBUG_MODE'])),
        ];

        foreach ($values as $key => $value) {
            $line = $key.'='.($value === '' || preg_match('/\s/', $value) ? '"'.$value.'"' : $value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $env = preg_match($pattern, $env) ? (string) preg_replace($pattern, $line, $env) : rtrim($env)."\n".$line."\n";
        }

        file_put_contents($this->path.'/.env', $env);
        $this->rewritten['.env'] = sha1($env);
    }

    /**
     * vendor/ and node_modules/ are cloned from the app's own checkout first
     * — on APFS that is copy-on-write and instant — so the install that
     * follows only has to reconcile the difference against the lock file.
     */
    private function installDependencies(string $repo): void
    {
        foreach (['vendor', 'node_modules'] as $dir) {
            if (! is_dir($repo.'/'.$dir) || is_dir($this->path.'/'.$dir)) {
                continue;
            }

            // Only a head start: if the copy fails or crawls, the install
            // below builds the directory from scratch instead. -c is macOS's
            // APFS clone flag and GNU cp rejects it, so elsewhere the same
            // copy-on-write is asked for with --reflink=auto, which falls
            // back to a plain copy on a filesystem that cannot clone.
            $clone = PHP_OS_FAMILY === 'Darwin' ? ['-cR'] : ['-R', '--reflink=auto'];

            try {
                $copied = Process::timeout(1200)->run(['cp', ...$clone, $repo.'/'.$dir, $this->path.'/'.$dir])->successful();
            } catch (ProcessTimedOutException) {
                $copied = false;
            }

            if (! $copied) {
                File::deleteDirectory($this->path.'/'.$dir);
                $this->notes[] = "Copying {$dir}/ from the app's checkout failed or took too long; it was installed from scratch instead.";
            }
        }

        $this->mustRun(
            Process::path($this->path)->timeout(1800)->env($this->environment()),
            [(string) config('scanner.composer'), 'install', '--no-interaction', '--no-progress', '--prefer-dist'],
            'composer install',
        );

        $package = is_file($this->path.'/package.json') ? json_decode((string) file_get_contents($this->path.'/package.json'), true) : null;

        if (! is_array($package) || ! isset($package['scripts']['build'])) {
            return;
        }

        $npm = (string) config('scanner.npm');

        if (! is_dir($this->path.'/node_modules')) {
            $this->mustRun(
                Process::path($this->path)->timeout(1800)->env($this->environment()),
                [$npm, is_file($this->path.'/package-lock.json') ? 'ci' : 'install', '--no-audit', '--no-fund'],
                'npm install',
            );
        }

        // Fatal, not a note: without built assets every test that renders a
        // page fails for this machine's reason, not the app's, and the scan
        // would report a broken suite that is not broken.
        $this->mustRun(
            Process::path($this->path)->timeout(1800)->env($this->environment()),
            [$npm, 'run', 'build'],
            'npm run build',
        );
    }

    private function createDatabase(): void
    {
        $connection = $this->connection();

        $connection->statement('DROP DATABASE IF EXISTS "'.$this->database.'" WITH (FORCE)');
        $connection->statement('CREATE DATABASE "'.$this->database.'"');
        $this->databaseCreated = true;
    }

    /**
     * Rewrite the worktree's phpunit config so every DB_* entry names the
     * scratch database, forced — many fleet apps force their own test
     * database, which would otherwise win over the process environment and
     * put two scans, or a scan and a developer, on the same database.
     */
    private function pointPhpunitAtDatabase(): void
    {
        $file = collect(['phpunit.xml', 'phpunit.xml.dist'])
            ->map(fn (string $name) => $this->path.'/'.$name)
            ->first(fn (string $path) => is_file($path));

        if ($file === null) {
            throw new RuntimeException('The app has no phpunit.xml, so there is no suite to run.');
        }

        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;

        if (! @$dom->load($file)) {
            throw new RuntimeException('phpunit.xml could not be parsed.');
        }

        $xpath = new DOMXPath($dom);
        $php = $xpath->query('/phpunit/php')->item(0);

        if (! $php instanceof DOMElement) {
            $php = $dom->createElement('php');
            $dom->documentElement->appendChild($php);
        }

        $values = array_intersect_key($this->environment(), array_flip([
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL',
        ]));

        foreach ($values as $name => $value) {
            foreach (['env', 'server'] as $tag) {
                foreach (iterator_to_array($xpath->query("/phpunit/php/{$tag}[@name='{$name}']")) as $existing) {
                    $existing->parentNode->removeChild($existing);
                }

                $element = $dom->createElement($tag);
                $element->setAttribute('name', $name);
                $element->setAttribute('value', $value);
                $element->setAttribute('force', 'true');
                $php->appendChild($element);
            }
        }

        $dom->save($file);
        $this->rewritten[basename($file)] = sha1_file($file);
    }

    private function removeWorktree(): void
    {
        $repo = (string) $this->app->local_path;

        $remove = Process::path($repo)->timeout(300)->run(['git', 'worktree', 'remove', '--force', $this->path]);

        if ($remove->failed() && is_dir($this->path)) {
            File::deleteDirectory($this->path);
        }

        Process::path($repo)->run(['git', 'worktree', 'prune']);
        $this->worktreeAdded = false;
    }

    private function connection(): Connection
    {
        return DB::connection((string) config('scanner.database.connection'));
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRun(PendingProcess $process, array $command, string $what): void
    {
        $result = $process->run($command);

        if ($result->failed()) {
            throw new RuntimeException($what.' failed: '.Str::limit(trim($result->errorOutput() ?: $result->output()), 2000));
        }
    }
}
