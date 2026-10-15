<?php

namespace App\Console\Commands;

use App\Workflows\Transfer;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Imports workflow files from disk — the journey files kept in this repo
 * under workflows/, or any export — the same way the page's Import does:
 * all or nothing per file, each workflow arriving switched off.
 */
class ImportWorkflows extends Command
{
    protected $signature = 'tests:import-workflows {files* : Workflow export files (.json)}';

    protected $description = 'Import workflow export files, switched off';

    public function handle(): int
    {
        $failed = false;

        foreach ($this->argument('files') as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                $this->error("{$path}: no such file.");
                $failed = true;

                continue;
            }

            try {
                $imported = Transfer::import((string) file_get_contents($path), source: basename($path));
            } catch (ValidationException $e) {
                $this->error("{$path}: ".collect($e->errors())->flatten()->first());
                $failed = true;

                continue;
            }

            foreach ($imported as $workflow) {
                $this->info("{$path}: imported \"{$workflow->name}\" (#{$workflow->id}), switched off.");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
