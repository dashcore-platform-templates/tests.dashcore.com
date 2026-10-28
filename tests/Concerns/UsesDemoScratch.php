<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;

/**
 * Points the demo's screenshot folders at a throwaway directory, so a test
 * that runs tests:demo never writes into — or wipes — this checkout's own
 * storage/*.noindex, whose run ids a test database reuses.
 */
trait UsesDemoScratch
{
    protected string $demoScratch;

    protected function setUpUsesDemoScratch(): void
    {
        $this->demoScratch = realpath(sys_get_temp_dir()).'/demo-scratch-'.bin2hex(random_bytes(4));

        config([
            'simulation.screenshots' => $this->demoScratch.'/simulation',
            'workflows.screenshots' => $this->demoScratch.'/workflows',
        ]);
    }

    protected function tearDownUsesDemoScratch(): void
    {
        File::deleteDirectory($this->demoScratch);
    }
}
