<?php

namespace Tests\Feature\Library;

use App\Enums\ScanStatus;
use App\Enums\SurfaceKind;
use App\Enums\SurfaceStatus;
use App\Enums\TestOutcome;
use App\Library\Library;
use App\Models\FleetApp;
use App\Models\LibraryFile;
use App\Models\LibraryTest as Test;
use App\Models\Scan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    private FleetApp $fleetApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = sys_get_temp_dir().'/lib-repo-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->repo);
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'test@example.test');
        $this->git('config', 'user.name', 'Test');

        $this->fleetApp = FleetApp::factory()->create(['key' => 'billing', 'local_path' => $this->repo]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    private function git(string ...$args): string
    {
        return trim(Process::path($this->repo)->run(['git', ...$args])->throw()->output());
    }

    /**
     * @param  array<string, string|null>  $files  path => content, null deletes
     */
    private function commit(array $files): string
    {
        foreach ($files as $path => $content) {
            $content === null ? File::delete("{$this->repo}/{$path}") : File::ensureDirectoryExists(dirname("{$this->repo}/{$path}"));
            if ($content !== null) {
                File::put("{$this->repo}/{$path}", $content);
            }
        }

        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', 'change');

        return $this->git('rev-parse', 'HEAD');
    }

    private function scan(string $sha, array $tests, array $evidence = []): Scan
    {
        $scan = Scan::factory()->completed()->for($this->fleetApp)->create(['commit_sha' => $sha]);

        foreach ($tests as [$id, $file, $name, $outcome, $assertions]) {
            $scan->tests()->create(['test_id' => $id, 'file' => $file, 'name' => $name, 'outcome' => $outcome, 'assertions' => $assertions]);
        }

        foreach ($evidence as $label => $ids) {
            $surface = $this->fleetApp->surfaces()->create(['kind' => SurfaceKind::Route, 'identifier' => $label, 'label' => $label, 'status' => SurfaceStatus::Tested]);
            $scan->surfaceResults()->create(['surface_id' => $surface->id, 'status' => SurfaceStatus::Tested, 'tests_count' => count($ids), 'evidence' => array_map(fn ($id) => ['id' => $id, 'outcome' => 'passed'], $ids)]);
        }

        return $scan;
    }

    private const PHPUNIT = <<<'PHP'
    <?php

    namespace Tests\Feature;

    class InvoiceTest extends TestCase
    {
        public function test_totals_add_up(): void
        {
            $this->assertTrue(true);
        }

        public function test_tax_by_region(): void
        {
            $this->assertTrue(true);
        }

        public function test_never_runs_yet(): void
        {
        }
    }
    PHP;

    private const PEST = <<<'PHP'
    <?php

    it('formats money', function (int $cents) {
        expect($cents)->toBeInt();
    })->with([1, 2]);
    PHP;

    public function test_it_keeps_every_test_file_and_ties_each_test_to_its_code_outcome_and_proofs(): void
    {
        $sha = $this->commit([
            'tests/Feature/InvoiceTest.php' => self::PHPUNIT,
            'tests/Unit/MoneyTest.php' => self::PEST,
            'packages/acme/kit/tests/KitTest.php' => '<?php test("kit works", fn () => expect(1)->toBe(1));',
            'tests/fixtures/notes.txt' => 'not php',
            'app/Models/Invoice.php' => '<?php class Invoice {}',
        ]);

        $scan = $this->scan($sha, [
            ['Tests\Feature\InvoiceTest::test_totals_add_up', 'tests/Feature/InvoiceTest.php', 'Totals add up', TestOutcome::Passed, 3],
            ['Tests\Feature\InvoiceTest::test_tax_by_region#eu', 'tests/Feature/InvoiceTest.php', 'Tax by region with data set "eu"', TestOutcome::Failed, 1],
            ['P\Tests\Unit\MoneyTest::__pest_evaluable_it_formats_money', 'tests/Unit/MoneyTest.php', 'it formats money with data set "(1)"', TestOutcome::Risky, 0],
        ], ['GET invoices' => ['Tests\Feature\InvoiceTest::test_totals_add_up'], 'GET invoices/{id}' => ['Tests\Feature\InvoiceTest::test_totals_add_up']]);

        $result = app(Library::class)->captureScan($scan);

        $this->assertSame(['files' => 3, 'changed' => 3], array_slice($result, 0, 2));
        $this->assertEqualsCanonicalizing(
            ['tests/Feature/InvoiceTest.php', 'tests/Unit/MoneyTest.php', 'packages/acme/kit/tests/KitTest.php'],
            LibraryFile::pluck('path')->all(),
        );
        $this->assertSame('Package: kit', LibraryFile::where('path', 'like', 'packages/%')->sole()->suite());

        $totals = Test::where('test_key', 'Tests\Feature\InvoiceTest::test_totals_add_up')->sole();
        $this->assertSame([7, 10, TestOutcome::Passed, 3, 2], [$totals->start_line, $totals->end_line, $totals->last_outcome, $totals->last_assertions, $totals->surfaces_count]);
        $this->assertSame([['scan' => $scan->id, 'outcome' => 'passed']], array_map(fn ($h) => array_intersect_key($h, ['scan' => 0, 'outcome' => 0]), $totals->history));

        $this->assertSame(12, Test::where('test_key', 'like', '%#eu')->sole()->start_line, 'a data provider run is tied to its method');
        $this->assertSame(3, Test::where('style', 'pest')->where('name', 'like', 'it formats%')->sole()->start_line, 'a dataset run is tied to its it()');

        $never = Test::where('name', 'test_never_runs_yet')->sole();
        $this->assertTrue($never->neverRan());
        $this->assertSame(17, $never->start_line);
        $this->assertSame(3, LibraryFile::where('path', 'tests/Feature/InvoiceTest.php')->sole()->tests_count);
    }

    public function test_a_changed_file_gets_a_revision_and_a_removed_one_is_marked_with_its_tests(): void
    {
        $first = $this->commit(['tests/Feature/InvoiceTest.php' => self::PHPUNIT, 'tests/Unit/MoneyTest.php' => self::PEST]);
        app(Library::class)->captureScan($this->scan($first, [['Tests\Feature\InvoiceTest::test_totals_add_up', 'tests/Feature/InvoiceTest.php', 'Totals add up', TestOutcome::Passed, 1]]));

        $second = $this->commit(['tests/Feature/InvoiceTest.php' => str_replace('assertTrue(true)', 'assertSame(1, 1)', self::PHPUNIT), 'tests/Unit/MoneyTest.php' => null]);
        $result = app(Library::class)->captureScan($this->scan($second, [['Tests\Feature\InvoiceTest::test_totals_add_up', 'tests/Feature/InvoiceTest.php', 'Totals add up', TestOutcome::Failed, 1]]));

        $invoice = LibraryFile::where('path', 'tests/Feature/InvoiceTest.php')->sole();
        $this->assertSame(1, $result['changed']);
        $this->assertCount(2, $invoice->revisions);
        $this->assertSame($second, $invoice->commit_sha);
        $this->assertStringContainsString('assertSame(1, 1)', $invoice->content);
        $this->assertStringContainsString('assertTrue(true)', $invoice->revisions->last()->content);

        $this->assertNotNull(LibraryFile::where('path', 'tests/Unit/MoneyTest.php')->sole()->removed_at);
        $this->assertNotNull(Test::where('style', 'pest')->sole()->removed_at);

        $totals = Test::where('test_key', 'Tests\Feature\InvoiceTest::test_totals_add_up')->sole();
        $this->assertSame(['passed', 'failed'], array_column($totals->history, 'outcome'));
        $this->assertSame(TestOutcome::Failed, $totals->last_outcome);
    }

    public function test_a_test_that_stops_running_keeps_its_last_outcome_and_history(): void
    {
        $sha = $this->commit(['tests/Feature/InvoiceTest.php' => self::PHPUNIT]);
        app(Library::class)->captureScan($this->scan($sha, [['Tests\Feature\InvoiceTest::test_totals_add_up', 'tests/Feature/InvoiceTest.php', 'Totals add up', TestOutcome::Passed, 1]]));
        app(Library::class)->captureScan($this->scan($sha, []));

        $totals = Test::where('test_key', 'Tests\Feature\InvoiceTest::test_totals_add_up')->sole();
        $this->assertSame(TestOutcome::Passed, $totals->last_outcome);
        $this->assertNull($totals->removed_at, 'its code is still there');
    }

    public function test_the_command_fills_the_library_from_each_apps_latest_scan(): void
    {
        $sha = $this->commit(['tests/Feature/InvoiceTest.php' => self::PHPUNIT]);
        $this->scan($sha, []);

        $this->artisan('tests:library', ['keys' => ['billing']])
            ->expectsOutputToContain('1 files (1 changed) · 3 tests')
            ->assertSuccessful();
    }

    public function test_a_scan_that_cannot_be_read_is_refused_with_why(): void
    {
        $scan = Scan::factory()->for($this->fleetApp)->create(['status' => ScanStatus::Completed, 'commit_sha' => null]);

        $this->expectExceptionMessage('has no checkout or commit');

        app(Library::class)->captureScan($scan);
    }
}
