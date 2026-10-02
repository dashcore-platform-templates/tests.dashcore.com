<?php

namespace Tests\Feature\Scanning;

use App\Enums\TestOutcome;
use App\Scanning\Evidence\ProbeReader;
use App\Scanning\Evidence\TestRecord;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The probe is the evidence everything else rests on, so it is tested the
 * way it runs: attached to a real PHPUnit process, from outside.
 */
class ProbeTest extends TestCase
{
    /** @var array<string, TestRecord> */
    private static array $records = [];

    private static bool $coverage = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$records !== []) {
            return;
        }

        $out = tempnam(sys_get_temp_dir(), 'probe');
        $xdebug = (string) config('scanner.xdebug');
        self::$coverage = is_file($xdebug);

        $args = [PHP_BINARY];
        if (self::$coverage) {
            array_push($args, '-d', 'zend_extension='.$xdebug, '-d', 'xdebug.mode=coverage');
        }

        $result = Process::path(base_path())
            ->timeout(300)
            ->env(['DASHCORE_PROBE_OUT' => $out, 'DASHCORE_PROBE_ROOT' => base_path(), 'XDEBUG_MODE' => self::$coverage ? 'coverage' : 'off'])
            ->run([...$args,
                '-d', 'auto_prepend_file='.base_path('probe/prepend.php'),
                'vendor/bin/phpunit',
                '--configuration', 'tests/Fixtures/probe/phpunit.xml',
                '--extension', 'DashcoreTestsProbe\\Extension',
                '--do-not-cache-result',
            ]);

        foreach ((new ProbeReader)->read($out) as $record) {
            self::$records[substr($record->id, strrpos($record->id, '::') + 2)] = $record;
        }

        @unlink($out);

        $this->assertCount(4, self::$records, "The probe should record every test.\n".$result->output().$result->errorOutput());
    }

    public static function tearDownAfterClass(): void
    {
        self::$records = [];

        parent::tearDownAfterClass();
    }

    public function test_it_records_the_routes_a_test_hit(): void
    {
        $record = self::$records['test_visits_the_welcome_page'];

        $this->assertSame(TestOutcome::Passed, $record->outcome);
        $this->assertGreaterThan(0, $record->assertions);
        $this->assertArrayHasKey('GET /', $record->keys);
        $this->assertSame('tests/Fixtures/probe/ProbeTargetTest.php', $record->file);
    }

    public function test_it_records_livewire_components_and_actions_by_name_and_class(): void
    {
        $keys = self::$records['test_opens_the_invite_modal']->keys;

        $this->assertArrayHasKey('team', $keys);
        $this->assertArrayHasKey('App\Livewire\Team\Index', $keys);
        $this->assertArrayHasKey('team@openInviteModal', $keys);
        $this->assertArrayHasKey('App\Livewire\Team\Index@openInviteModal', $keys);

        foreach (array_keys($keys) as $key) {
            $this->assertStringStartsNotWith('POST livewire', $key, 'Livewire\'s own endpoint is not an app route');
        }
    }

    public function test_it_records_outcomes_and_assertion_counts(): void
    {
        $this->assertSame(0, self::$records['test_asserts_nothing']->assertions);
        $this->assertFalse(self::$records['test_asserts_nothing']->proves());

        $failed = self::$records['test_fails'];
        $this->assertSame(TestOutcome::Failed, $failed->outcome);
        $this->assertStringContainsString('deliberately failing', (string) $failed->message);
    }

    public function test_it_records_only_the_app_lines_each_test_body_executed(): void
    {
        if (! self::$coverage) {
            $this->markTestSkipped('Xdebug is not available at SCANNER_XDEBUG on this machine.');
        }

        $lines = self::$records['test_opens_the_invite_modal']->lines;

        $this->assertArrayHasKey('app/Livewire/Team/Index.php', $lines);
        foreach (array_keys($lines) as $file) {
            $this->assertStringStartsWith('app/', $file, 'vendor and tests are not surfaces');
        }

        $this->assertArrayNotHasKey('app/Livewire/Team/Index.php', self::$records['test_visits_the_welcome_page']->lines, 'evidence is per test');
    }
}
