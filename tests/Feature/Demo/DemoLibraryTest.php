<?php

namespace Tests\Feature\Demo;

use App\Models\LibraryFile;
use App\Models\LibraryTest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DemoLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_library_shows_demo_files_with_revisions_outcome_history_and_proofs_without_git(): void
    {
        Process::fake();
        $owner = User::factory()->owner()->create();

        $this->artisan('tests:demo')->assertSuccessful();

        Process::assertNothingRan();

        $file = LibraryFile::query()->whereRelation('fleetApp', 'key', 'demo-sales')->where('path', 'tests/Feature/LeadsTest.php')->sole();
        $this->assertGreaterThan(1, $file->revisions()->count());

        $assign = LibraryTest::query()->where('library_file_id', $file->id)->where('name', 'an owner can assign a lead to a rep')->sole();
        $this->assertSame(['passed', 'failed'], array_column($assign->history, 'outcome'));

        $index = LibraryTest::query()->where('library_file_id', $file->id)->where('name', 'the leads page lists the rep\'s leads')->sole();
        $this->assertSame(['passed', 'passed', 'passed'], array_column($index->history, 'outcome'));
        $this->assertGreaterThan(0, $index->surfaces_count);

        $this->actingAs($owner)->get(route('library.index'))
            ->assertOk()
            ->assertSee(['Demo Sales', 'Demo Finance', 'Demo Website']);

        $this->actingAs($owner)->get(route('library.app', 'demo-sales'))
            ->assertOk()
            ->assertSee(['Feature/LeadsTest.php', '1 failing']);

        $this->actingAs($owner)->get(route('library.file', ['key' => 'demo-sales', 'path' => 'tests/Feature/LeadsTest.php', 'test' => $index->id]))
            ->assertOk()
            ->assertSee(['Revisions', 'an owner can assign a lead to a rep', 'Last 3 scans', 'Proves '.$index->surfaces_count.' surfaces', 'leads.board'])
            ->assertSee('title="failed', false)
            ->assertSee('title="passed', false);
    }
}
