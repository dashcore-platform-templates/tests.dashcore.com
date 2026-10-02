<?php

namespace Tests\Feature\Scanning;

use App\Enums\TestOutcome;
use App\Models\FleetApp;
use App\Models\Scan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneScansTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_scans_keep_their_summary_but_lose_their_detail(): void
    {
        $app = FleetApp::factory()->create();
        $other = FleetApp::factory()->create();
        $surface = $app->surfaces()->create(['kind' => 'route', 'identifier' => 'GET a', 'label' => 'GET /a']);

        $scans = Scan::factory()->completed()->for($app)->count(4)->create(['output' => 'log']);
        $otherScan = Scan::factory()->completed()->for($other)->create(['output' => 'log']);

        foreach ([...$scans, $otherScan] as $scan) {
            $scan->tests()->create(['test_id' => 'T::a', 'name' => 'a', 'outcome' => TestOutcome::Passed]);
            $scan->surfaceResults()->create(['surface_id' => $surface->id, 'status' => 'tested']);
        }

        $this->artisan('tests:prune', ['--keep' => 2])->expectsOutputToContain('Pruned 4 detail row(s)')->assertSuccessful();

        [$oldest, $older, $newer, $newest] = $scans->sortBy('id')->values()->all();

        foreach ([$oldest, $older] as $pruned) {
            $this->assertSame(0, $pruned->tests()->count());
            $this->assertSame(0, $pruned->surfaceResults()->count());
            $this->assertNull($pruned->fresh()->output);
            $this->assertNotNull($pruned->fresh()->score, 'the summary stays for the trend');
        }

        foreach ([$newer, $newest, $otherScan] as $kept) {
            $this->assertSame(1, $kept->tests()->count());
            $this->assertSame('log', $kept->fresh()->output);
        }
    }
}
