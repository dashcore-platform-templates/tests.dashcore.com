<?php

namespace Dashcore\DesignKit\Commands;

use Dashcore\DesignKit\ActionsToken;
use Dashcore\DesignKit\Allowance;
use Dashcore\DesignKit\Audit;
use Dashcore\DesignKit\Report;
use Dashcore\DesignKit\Source;
use Dashcore\DesignKit\Style;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SplFileInfo;

/**
 * php artisan design:audit [path]
 *
 * Lists everything in the app that is off the standard, per file. This is the
 * map a migration works from, and the check an agent runs on one screen after
 * changing it: the path can be a directory or a single Blade file. --fix
 * applies only the mechanical half, weights and off-scale spacing, which have
 * no judgment in them. Everything else is reported and left alone.
 *
 * With design/allowance.json in place (--baseline writes it) the audit is a
 * ratchet: it fails only when a rule's count rises above the allowance, so an
 * app far off the standard can be held to it without a red build. See
 * Allowance.
 */
class AuditCommand extends Command
{
    protected $signature = 'design:audit
        {path=resources/views : A directory to walk, or one Blade file}
        {--fix : Apply the mechanical rewrites (weights, off-scale spacing) and report the rest}
        {--full : List every finding rather than the first few per file}
        {--report : Also send the counts to design.dashcore.com, for the fleet view}
        {--baseline : Record today\'s counts as this app\'s allowance, in design/allowance.json; it only ever shrinks}';

    protected $description = 'Find what in this app is off the Dashcore Standard';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_string($path) || $path === '') {
            $this->components->error('Give one directory or one Blade file.');

            return self::FAILURE;
        }

        $root = base_path($path);

        if (File::isFile($root)) {
            $files = collect([new SplFileInfo($root)]);
        } elseif (File::isDirectory($root)) {
            $files = collect(File::allFiles($root))
                ->filter(fn ($f) => str_ends_with($f->getFilename(), '.blade.php'))
                // Flux's own published stubs are the framework's, not ours to police.
                ->reject(fn ($f) => str_starts_with(str_replace('\\', '/', $f->getRelativePath()), 'flux'));
        } elseif ($path === 'resources/views') {
            // An app with no Blade views at all, such as a site of static pages,
            // has nothing for these rules to read. That is zero findings, not an
            // error: an error here turned a reporting build red and left the
            // allowance unwritable. A path somebody typed still has to exist.
            $files = collect();
        } else {
            $this->components->error("Nothing at {$path}.");

            return self::FAILURE;
        }

        $byRule = [];
        $totalFindings = 0;
        $filesWithFindings = 0;
        $fixedFiles = 0;
        $skipped = [];

        foreach ($files as $file) {
            $relative = $this->relative($file->getPathname());
            $before = File::get($file->getPathname());
            $contents = $before;

            if (Audit::optsOut($contents)) {
                $skipped[] = $relative;

                continue;
            }

            if ($this->option('fix')) {
                [$contents, $changed] = Audit::mechanical($contents);
                if ($changed !== []) {
                    File::put($file->getPathname(), $contents);
                    $fixedFiles++;
                }
            }

            $findings = Audit::file($contents, $relative);
            if ($findings === []) {
                continue;
            }

            $filesWithFindings++;
            $totalFindings += count($findings);

            // A plain line rather than a padded two-column detail. Livewire 4
            // names single-file components with a lightning bolt, which the
            // console's width maths count as one column and the terminal draws
            // as two, so a padded right column comes out garbled.
            $this->line('');
            $this->line("  <fg=cyan>{$relative}</>  <fg=gray>".count($findings).' '.Str::plural('finding', count($findings)).'</>');

            $shown = $this->option('full') ? $findings : array_slice($findings, 0, 4);
            foreach ($shown as $f) {
                $byRule[$f['rule']] = ($byRule[$f['rule']] ?? 0) + 1;
                $this->line("    <fg=gray>{$f['line']}</>  {$f['rule']}: <fg=yellow>{$f['snippet']}</>");
            }
            if (count($findings) > count($shown)) {
                $this->line('    <fg=gray>'.(count($findings) - count($shown)).' more, run with --full</>');
            }

            foreach (array_slice($findings, count($shown)) as $f) {
                $byRule[$f['rule']] = ($byRule[$f['rule']] ?? 0) + 1;
            }
        }

        $this->line('');
        if ($fixedFiles > 0) {
            $this->components->info("Fixed {$fixedFiles} ".Str::plural('file', $fixedFiles).'. Check the diff, then run your tests.');
        }

        if ($skipped !== []) {
            $this->components->twoColumnDetail('<fg=gray>Skipped by request</>', count($skipped).' '.Str::plural('file', count($skipped)));
            foreach ($skipped as $s) {
                $this->line("    <fg=gray>{$s}</>");
            }
            $this->line('');
        }

        $this->report($byRule, $filesWithFindings, $files->count());

        $allowancePath = base_path(Allowance::FILE);

        if ($this->option('baseline')) {
            File::ensureDirectoryExists(dirname($allowancePath));
            File::put($allowancePath, Allowance::record($byRule, Allowance::read($allowancePath)));
            $this->components->info("Recorded {$totalFindings} ".Str::plural('finding', $totalFindings).' as the allowance in '.Allowance::FILE.'. From here the audit fails only when a count rises.');

            return self::SUCCESS;
        }

        if ($totalFindings === 0) {
            $this->components->info('Nothing off the standard.');

            return self::SUCCESS;
        }

        arsort($byRule);
        // "22 in 8" is a riddle: in eight what. The unit goes in, the same way
        // the fleet board's findings column carries it.
        $this->components->twoColumnDetail('<fg=cyan>By rule</>', "{$totalFindings} in {$filesWithFindings} ".Str::plural('file', $filesWithFindings));
        foreach ($byRule as $rule => $count) {
            $this->components->twoColumnDetail("  {$rule}", (string) $count);
        }

        $this->line('');
        // None of these are mechanical, or --fix would have taken them.
        // A pack brings design/MIGRATION.md. An app on a style, or one that
        // only reports, has none, and the rules live in this package.
        $this->components->warn(File::exists(base_path('design/MIGRATION.md'))
            ? 'Work through these with design/MIGRATION.md: one screen per commit, most-used first.'
            : 'Each rule is in vendor/dashcore/design-kit/STANDARD.md. Fix one screen per commit, most-used first, then lower the allowance with --baseline.');

        // Reporting is telling the board what is true, which succeeds whatever
        // it found. Exiting non-zero here turned the one step every app is told
        // to add into a red build, and both the guide and the prompt promised
        // it could not: the snippet has no continue-on-error because it was
        // never supposed to need one.
        //
        // It also decided policy nobody chose. A new rule could turn fifteen
        // builds red, which is the only reason adding a rule was ever a major.
        // An app that does want findings to fail its build runs the audit
        // without --report, deliberately, as its own check.
        if ($this->option('report')) {
            return self::SUCCESS;
        }

        $allowance = Allowance::read($allowancePath);

        return $allowance === null ? self::FAILURE : $this->ratchet($byRule, $allowance);
    }

    /**
     * The audit's answer once an app has an allowance: worse than recorded
     * fails, anything else passes and says what it saw.
     *
     * @param  array<string,int>  $byRule
     * @param  array{known: list<string>, rules: array<string,int>}  $allowance
     */
    private function ratchet(array $byRule, array $allowance): int
    {
        ['rises' => $rises, 'unrecorded' => $unrecorded, 'below' => $below] = Allowance::compare($byRule, $allowance);

        $this->line('');
        foreach ($rises as $rule => [$now, $allowed]) {
            $this->components->twoColumnDetail("  <fg=red>{$rule}</>", "<fg=red>{$now}, allowed {$allowed}</>");
        }
        foreach ($unrecorded as $rule => $now) {
            // A rule this release added. Allowed until it is recorded, so a new
            // rule never turns a build red on the day it ships.
            $this->components->twoColumnDetail("  {$rule}", "{$now}, a new rule: allowed until --baseline records it");
        }
        foreach ($below as $rule => [$now, $allowed]) {
            $this->components->twoColumnDetail("  <fg=green>{$rule}</>", "<fg=green>{$now}, allowed {$allowed}</>");
        }

        if ($rises !== []) {
            $this->line('');
            $this->components->error('Worse than the allowance in '.Allowance::FILE.'. Fix what this change added; the allowance does not go up.');

            return self::FAILURE;
        }

        $this->line('');
        $this->components->info($below === []
            ? 'Within the allowance.'
            : 'Within the allowance, and below it. Run design:audit --baseline to lower it, so the gain is kept.');

        return self::SUCCESS;
    }

    /**
     * Send the counts to the tool, so the fleet view has a row for this app.
     *
     * It never fails the audit. The audit's answer is about this repo, and a
     * tool that is down or a token that was never set does not change it. A
     * build that goes red because a dashboard could not be updated teaches
     * people to pass --report less often, which is the opposite of the point.
     *
     * @param  array<string,int>  $byRule
     */
    private function report(array $byRule, int $filesWithFindings, int $filesChecked): void
    {
        if (! $this->option('report')) {
            return;
        }

        $source = rtrim((string) config('design-kit.source'), '/');

        // GitHub's word first. A workflow with id-token permission proves which
        // repository this is without anybody pasting a secret into it, and a
        // repository proving itself that way joins the board by reporting.
        $token = ActionsToken::forAudience($source)
            // Still supported, because every app in the fleet is on one today.
            ?? (string) config('design-kit.token');

        if ((string) $token === '') {
            $this->line('');

            if (ActionsToken::inActions() && ! ActionsToken::hasPermission()) {
                // The one mistake worth naming outright. Everything else is in
                // place and one line is missing from the workflow.
                $this->line('  <fg=gray>Nothing reported: this job has no id-token permission. Add beside runs-on:</>');
                $this->line('');
                $this->line('    <fg=cyan>permissions:</>');
                $this->line('      <fg=cyan>id-token: write</>');
                $this->line('      <fg=cyan>contents: read</>');
                $this->line('');
                // Naming any permission turns GitHub's defaults off, so a job
                // asking for id-token alone cannot check itself out. It still
                // runs, which is what makes it expensive to find.
                $this->line('  <fg=gray>Keep contents: read, or the job cannot check itself out.</>');

                return;
            }

            // Outside Actions there is nothing to prove identity with, and an
            // older kit's DESIGN_TOKEN was not set either.
            $this->line('  <fg=gray>Nothing reported: --report only works in GitHub Actions.</>');

            return;
        }

        $design = File::exists(base_path('design/design.json'))
            ? json_decode(File::get(base_path('design/design.json')), true)
            : null;

        $style = is_array($design) && is_string($design['style'] ?? null) ? $design['style'] : null;

        // An app on a design has the standard in its pack. An app on a style
        // has it in this package, which is the standard it is held to.
        $standardFile = File::exists(base_path('design/STANDARD.md'))
            ? base_path('design/STANDARD.md')
            : ($style !== null && File::exists(Style::standardPath()) ? Style::standardPath() : null);
        $standard = $standardFile !== null ? Source::standardVersion(File::get($standardFile)) : null;

        $allowance = Allowance::read(base_path(Allowance::FILE));

        $payload = Report::payload(
            $byRule,
            $filesWithFindings,
            $filesChecked,
            $standard,
            is_array($design) ? Source::designSlug($design) : null,
            style: $style,
            allowance: $allowance === null ? null : array_sum($allowance['rules']),
        );

        $url = $source.'/api/reports';

        $this->line('');

        try {
            $response = Http::timeout(15)->withToken($token)->acceptJson()->post($url, $payload);
        } catch (ConnectionException $e) {
            $this->line("  <fg=gray>Could not reach {$url}, so nothing was reported. ({$e->getMessage()})</>");

            return;
        }

        if ($response->failed()) {
            $this->line("  <fg=gray>{$url} answered HTTP {$response->status()}, so nothing was reported.</>");

            if ($response->status() === 401) {
                // 401 is the credential and nothing else, so it is worth
                // naming both paths: the current one is id-token, and every
                // app still on an older kit sends a DESIGN_TOKEN.
                $this->line('  <fg=gray>Check the workflow asks for id-token: write. On an older kit, reissue DESIGN_TOKEN on design.dashcore.com.</>');
            }

            return;
        }

        // The tool knows what the standard reads today, so it says so on the
        // way back. An app therefore learns it is behind by reporting, without
        // a second call and without anybody asking.
        $current = $response->json('standard_version');
        $this->line('  <fg=gray>Reported to '.$url.'.</>');

        if (is_string($current) && $standard !== null && $current !== $standard) {
            $this->components->warn("The standard has moved since this app applied: {$standard} is now {$current}.");
        }
    }

    /** The path from the repo root, with forward slashes on every platform. */
    private function relative(string $path): string
    {
        $base = str_replace('\\', '/', base_path()).'/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
