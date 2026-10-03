<?php

namespace Tests\Feature\Library;

use App\Enums\SurfaceKind;
use App\Enums\SurfaceStatus;
use App\Enums\TestOutcome;
use App\Livewire\Library\AppTests;
use App\Livewire\Library\FileView;
use App\Livewire\Library\Index;
use App\Models\FleetApp;
use App\Models\LibraryFile;
use App\Models\LibraryTest;
use App\Models\Scan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LibraryPagesTest extends TestCase
{
    use RefreshDatabase;

    private FleetApp $fleetApp;

    private LibraryFile $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fleetApp = FleetApp::factory()->create(['key' => 'billing', 'name' => 'Billing']);
        $this->file = LibraryFile::create([
            'fleet_app_id' => $this->fleetApp->id, 'path' => 'tests/Feature/InvoiceTest.php', 'hash' => sha1('x'),
            'content' => "<?php\n\nclass InvoiceTest\n{\n    public function test_totals(): void\n    {\n        \$this->assertTrue(true); // refund\n    }\n}\n",
            'lines' => 10, 'tests_count' => 3, 'commit_sha' => str_repeat('a', 40), 'first_seen_at' => now(), 'changed_at' => now(),
        ]);
        $this->file->revisions()->create(['hash' => sha1('x'), 'commit_sha' => str_repeat('a', 40), 'content' => 'x', 'lines' => 10, 'created_at' => now()]);
    }

    private function test(string $name, ?TestOutcome $outcome, int $assertions = 1, array $extra = []): LibraryTest
    {
        return LibraryTest::create([
            'fleet_app_id' => $this->fleetApp->id, 'library_file_id' => $this->file->id,
            'test_key' => 'Tests\Feature\InvoiceTest::'.$name, 'test_key_hash' => sha1($name), 'name' => $name,
            'style' => 'phpunit', 'start_line' => 5, 'end_line' => 8, 'last_outcome' => $outcome, 'last_assertions' => $assertions,
            'first_seen_at' => now(), 'last_seen_at' => now(), ...$extra,
        ]);
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get(route('library.index'))->assertRedirect(route('login'));
    }

    public function test_the_library_sums_up_the_fleet_and_is_in_the_navigation(): void
    {
        $this->test('test_totals', TestOutcome::Passed);
        $this->test('test_tax', TestOutcome::Failed);
        $this->test('test_hollow', TestOutcome::Risky, 0);
        $this->test('test_new', null);

        $this->actingAs(User::factory()->create())
            ->get(route('library.index'))
            ->assertOk()
            ->assertSeeLivewire('library.index')
            ->assertSee(route('library.index'));

        $totals = Livewire::test(Index::class)->instance()->totals;
        $this->assertSame([4, 1, 1, 1, 1], [$totals['tests'], $totals['passed'], $totals['failing'], $totals['hollow'], $totals['never_ran']]);
    }

    public function test_search_finds_tests_by_name_and_files_by_their_code(): void
    {
        $this->actingAs(User::factory()->create());
        $this->test('test_totals', TestOutcome::Passed);

        Livewire::test(Index::class)
            ->set('search', 'totals')
            ->assertSee('Tests named “totals”')
            ->assertSee('test_totals')
            ->set('search', 'refund')
            ->assertSee('billing · tests/Feature/InvoiceTest.php');
    }

    public function test_an_apps_page_groups_files_by_suite_and_filters_to_trouble(): void
    {
        $this->actingAs(User::factory()->create());
        $this->test('test_totals', TestOutcome::Passed);
        LibraryFile::create([
            'fleet_app_id' => $this->fleetApp->id, 'path' => 'tests/Unit/MoneyTest.php', 'hash' => sha1('y'), 'content' => '<?php', 'lines' => 1,
            'first_seen_at' => now(), 'changed_at' => now(),
        ]);

        Livewire::test(AppTests::class, ['appKey' => 'billing'])
            ->assertSeeInOrder(['Feature', 'InvoiceTest.php', 'Unit', 'MoneyTest.php'])
            ->set('show', 'failing')
            ->assertDontSee('InvoiceTest.php');

        $this->get(route('library.app', 'nope'))->assertNotFound();
    }

    public function test_a_file_shows_its_code_its_tests_and_what_the_open_one_proves(): void
    {
        $this->actingAs(User::factory()->create());
        $scan = Scan::factory()->completed()->for($this->fleetApp)->create();
        $passing = $this->test('test_totals', TestOutcome::Passed, 3, ['last_scan_id' => $scan->id, 'history' => [['scan' => $scan->id, 'outcome' => 'passed', 'at' => now()->toIso8601String()]]]);
        $surface = $this->fleetApp->surfaces()->create(['kind' => SurfaceKind::Route, 'identifier' => 'GET invoices', 'label' => 'GET /invoices', 'status' => SurfaceStatus::Tested]);
        $scan->surfaceResults()->create(['surface_id' => $surface->id, 'status' => SurfaceStatus::Tested, 'tests_count' => 1, 'evidence' => [['id' => $passing->test_key, 'outcome' => 'passed']]]);

        Livewire::withQueryParams(['path' => 'tests/Feature/InvoiceTest.php'])
            ->test(FileView::class, ['appKey' => 'billing'])
            ->assertSeeHtml('class="hl-keyword"')
            ->assertSee('test_totals')
            ->call('open', $passing->id)
            ->assertSee('Proves 1 surface')
            ->assertSee('GET /invoices')
            ->assertSee('Last 1 scans');
    }

    public function test_an_unknown_file_is_not_found(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('library.file', ['key' => 'billing', 'path' => 'tests/Nope.php']))
            ->assertNotFound();
    }
}
