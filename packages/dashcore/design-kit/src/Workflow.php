<?php

namespace Dashcore\DesignKit;

/**
 * What the repository's own workflows say about reporting to the board.
 *
 * This is the advantage of proving identity with GitHub over pasting a secret
 * into GitHub: what is missing is in a file here, so something can read it and
 * say so. A missing repository secret is visible to nothing.
 *
 * Pure: it reads files and returns facts. `design:connect` does the talking.
 */
final class Workflow
{
    /** The step a workflow needs, with the guard that keeps the board honest. */
    public const STEP = <<<'YAML'
- name: Report to design.dashcore.com
  if: github.ref_name == github.event.repository.default_branch
  env:
    DESIGN_SOURCE: https://design.dashcore.com
  run: php artisan design:audit --report
YAML;

    /**
     * Naming any permission turns the defaults off, so contents: read is not
     * optional padding. A job that only asks for id-token cannot check itself
     * out.
     */
    public const PERMISSIONS = <<<'YAML'
permissions:
  id-token: write
  contents: read
YAML;

    /** How the reporting step is recognised, wherever somebody has put it. */
    private const MARKER = 'design:audit --report';

    /**
     * The state of reporting in a repository.
     *
     * `file` is the workflow that reports, or null when none does. `proves` is
     * whether that job can ask GitHub who it is, which is the difference
     * between a row appearing and a job that runs and sends nothing. `guarded`
     * is whether it is held to the default branch, without which a pull
     * request build overwrites the row with a branch nobody has merged.
     *
     * @return array{file: ?string, proves: bool, guarded: bool}
     */
    public static function inspect(string $directory): array
    {
        foreach (self::files($directory) as $path) {
            $body = (string) file_get_contents($path);

            if (! str_contains($body, self::MARKER)) {
                continue;
            }

            return [
                'file' => basename($path),
                // Read across the file rather than within the one job. Being
                // precise would mean parsing YAML to find which job holds the
                // step, and being wrong in this direction only means somebody
                // is told to check a line that is already there.
                'proves' => str_contains($body, 'id-token'),
                'guarded' => str_contains($body, 'github.event.repository.default_branch'),
            ];
        }

        return ['file' => null, 'proves' => false, 'guarded' => false];
    }

    /**
     * owner/name out of a git remote, in either shape it comes in.
     *
     * Returns null for anything that is not GitHub, because the board is keyed
     * by GitHub repositories and a remote somewhere else cannot report.
     */
    public static function repository(string $remote): ?string
    {
        $remote = trim($remote);
        $remote = (string) preg_replace('#^git@github\.com:#', '', $remote);
        $remote = (string) preg_replace('#^(https?|ssh)://(git@)?github\.com/#', '', $remote);
        $remote = (string) preg_replace('#\.git$#', '', $remote);
        $remote = rtrim($remote, '/');

        return preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $remote) === 1 ? $remote : null;
    }

    /** @return list<string> */
    private static function files(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $found = glob(rtrim($directory, '/\\').'/*.{yml,yaml}', GLOB_BRACE);

        return $found === false ? [] : array_values($found);
    }
}
