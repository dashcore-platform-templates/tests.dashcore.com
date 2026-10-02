<?php

namespace App\Scanning\Evidence;

use App\Enums\TestOutcome;

/**
 * What one test did, as the probe recorded it.
 */
final readonly class TestRecord
{
    /**
     * @param  array<string, true>  $keys  runtime evidence: routes, component names and classes, actions
     * @param  array<string, list<int>>  $lines  executed lines of app/, by relative path
     */
    public function __construct(
        public string $id,
        public ?string $file,
        public string $name,
        public TestOutcome $outcome,
        public int $assertions,
        public int $durationMs,
        public ?string $message,
        public array $keys,
        public array $lines,
    ) {}

    /**
     * Whether this test reaching a surface proves the surface works: it passed
     * and it asserted something. A green test with no assertions only proves
     * the code did not throw.
     */
    public function proves(): bool
    {
        return $this->outcome->proves() && $this->assertions > 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromProbe(array $row): self
    {
        $keys = [];

        foreach (['routes', 'mounts', 'calls', 'mount_classes', 'call_classes'] as $field) {
            foreach ((array) ($row[$field] ?? []) as $value) {
                if (is_string($value)) {
                    $keys[self::normalise($value)] = true;
                }
            }
        }

        $lines = [];

        foreach ((array) ($row['lines'] ?? []) as $file => $executed) {
            $lines[(string) $file] = array_map('intval', (array) $executed);
        }

        return new self(
            id: (string) ($row['id'] ?? ''),
            file: isset($row['file']) ? (string) $row['file'] : null,
            name: (string) ($row['name'] ?? $row['id'] ?? ''),
            outcome: TestOutcome::tryFrom((string) ($row['status'] ?? '')) ?? TestOutcome::Errored,
            assertions: (int) ($row['assertions'] ?? 0),
            durationMs: (int) ($row['duration_ms'] ?? 0),
            message: isset($row['message']) ? (string) $row['message'] : null,
            keys: $keys,
            lines: $lines,
        );
    }

    /**
     * Component names reach the probe in Livewire's own form — `pages::auth.login`,
     * sometimes with the `⚡` marker — and are compared in one canonical form.
     */
    public static function normalise(string $key): string
    {
        return str_replace('⚡', '', $key);
    }
}
