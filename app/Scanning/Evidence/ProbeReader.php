<?php

namespace App\Scanning\Evidence;

use SplFileObject;

/**
 * Reads the probe's one-JSON-object-per-line output. A line the suite cut
 * short (a fatal error mid-write) is skipped and counted, not fatal: every
 * complete line before it is still good evidence.
 */
class ProbeReader
{
    public int $skipped = 0;

    /**
     * @return list<TestRecord>
     */
    public function read(string $path): array
    {
        $this->skipped = 0;

        if (! is_file($path)) {
            return [];
        }

        $records = [];
        $file = new SplFileObject($path);

        while (! $file->eof()) {
            $line = trim((string) $file->fgets());

            if ($line === '') {
                continue;
            }

            $row = json_decode($line, true);

            if (! is_array($row) || ! isset($row['id'])) {
                $this->skipped++;

                continue;
            }

            $records[] = TestRecord::fromProbe($row);
        }

        return $records;
    }
}
