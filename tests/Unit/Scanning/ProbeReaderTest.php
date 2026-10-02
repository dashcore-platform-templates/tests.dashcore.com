<?php

namespace Tests\Unit\Scanning;

use App\Enums\TestOutcome;
use App\Scanning\Evidence\ProbeReader;
use PHPUnit\Framework\TestCase;

class ProbeReaderTest extends TestCase
{
    public function test_complete_lines_are_read_and_a_truncated_one_is_counted_not_fatal(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'probe');
        file_put_contents($path, implode("\n", [
            json_encode(['id' => 'T::a', 'file' => 'tests/T.php', 'name' => 'a', 'status' => 'passed', 'assertions' => 3, 'duration_ms' => 12, 'routes' => ['GET /'], 'calls' => ['team@save'], 'call_classes' => ['App\Livewire\Team@save'], 'lines' => ['app/X.php' => [3, 4]]]),
            json_encode(['id' => 'T::b', 'status' => 'failed', 'message' => 'boom', 'assertions' => 1]),
            '{"id":"T::c","status":"pas',
            '',
        ]));

        $reader = new ProbeReader;
        $records = $reader->read($path);
        unlink($path);

        $this->assertCount(2, $records);
        $this->assertSame(1, $reader->skipped);

        $this->assertSame('T::a', $records[0]->id);
        $this->assertSame(TestOutcome::Passed, $records[0]->outcome);
        $this->assertTrue($records[0]->proves());
        $this->assertSame(['GET /' => true, 'team@save' => true, 'App\Livewire\Team@save' => true], $records[0]->keys);
        $this->assertSame(['app/X.php' => [3, 4]], $records[0]->lines);

        $this->assertSame(TestOutcome::Failed, $records[1]->outcome);
        $this->assertSame('boom', $records[1]->message);
        $this->assertFalse($records[1]->proves());
    }

    public function test_a_missing_file_has_no_records(): void
    {
        $this->assertSame([], (new ProbeReader)->read('/nonexistent/probe.ndjson'));
    }
}
