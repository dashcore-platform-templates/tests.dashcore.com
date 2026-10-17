<?php

namespace Dashcore\DesignKit\Commands;

use Dashcore\DesignKit\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * php artisan design:update <slug>
 *
 * Pick up a change to the standard on an app that is already on it. Setting an
 * app up is a different job with one-time steps in it, so this is not that:
 * nothing here installs the adapter, replaces the sign-in layout, or touches
 * CLAUDE.md.
 *
 * It does not run `composer update` for you. Updating the package whose code is
 * currently executing is not something a command should do to itself, and the
 * new audit rules arrive with that package, so it has to happen first. The
 * command says so rather than doing it.
 */
class UpdateCommand extends Command
{
    protected $signature = 'design:update
        {source? : The design slug or pack URL. Defaults to whatever design:apply defaults to.}
        {--force : Answer yes to the questions design:apply would ask}';

    protected $description = 'Pick up a change to the standard on an app already using it';

    public function handle(): int
    {
        $file = base_path('design/design.json');

        if (! File::exists($file)) {
            // Setting an app up is a different job with its own prompt. This
            // says where that is rather than explaining the difference.
            $this->components->error('No design/design.json here. Apply a design first.');
            $this->line('  <fg=gray>https://design.dashcore.com/designs</>');

            return self::FAILURE;
        }

        $source = $this->argument('source');

        // An app on a built-in style names it, and there is nothing to fetch:
        // the style arrived with the package. Re-applying puts back anything a
        // release moved, such as a renamed stylesheet, and audits as below.
        $current = json_decode(File::get($file), true);
        $style = is_array($current) && is_string($current['style'] ?? null) ? $current['style'] : null;
        if ($style !== null && (! is_string($source) || $source === '')) {
            $this->line("  <fg=gray>Using the {$style} style, which this app already has.</>");
            $this->components->info('Update dashcore/design-kit before this, if you have not. The style ships in it.');

            if ($this->call('design:apply', ['--style' => $style]) !== self::SUCCESS) {
                $this->components->error('The style did not apply. Nothing was audited.');

                return self::FAILURE;
            }

            $this->line('');
            $this->call('design:audit');

            return self::SUCCESS;
        }

        // The design file names its own slug, so an app on the standard can be
        // updated without anybody remembering which design it took.
        if (! is_string($source) || $source === '') {
            $design = json_decode(File::get($file), true);
            $source = is_array($design) ? Source::designSlug($design) : null;

            if ($source === null) {
                // design/design.json here predates designs carrying their own
                // slug. Passing it once writes it in, and no later run asks.
                $this->components->error('Give the design slug once, the same one you applied with.');
                $this->line('  <fg=gray>php artisan design:update harbor-point</>');

                return self::FAILURE;
            }

            $this->line("  <fg=gray>Using {$source}, the design this app already has.</>");
        }

        // The components and the audit rules ship in the package, so an update
        // that skips Composer re-applies the design and then audits it against
        // the rules the app already had. Plain `composer update` is not enough
        // either: it stays inside the major the app is pinned to. The site
        // names the current major, which this package cannot see from here.
        $this->components->info('Update dashcore/design-kit before this, if you have not.');

        $applied = $this->call('design:apply', array_filter([
            'source' => $source,
            '--force' => (bool) $this->option('force'),
        ]));

        if ($applied !== self::SUCCESS) {
            $this->components->error('The design did not apply. Nothing was audited.');

            return self::FAILURE;
        }

        $this->line('');
        $this->components->info('Auditing against the standard as it reads today.');

        // The audit exits non-zero while anything is off, which is right for the
        // audit and wrong here. An update that reports work to do has worked.
        $this->call('design:audit');

        $this->line('');
        $this->components->info('Updated. design/CHANGELOG.md says what moved, design/MIGRATION.md says how to work the findings.');

        return self::SUCCESS;
    }
}
