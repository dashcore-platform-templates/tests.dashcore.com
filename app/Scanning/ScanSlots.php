<?php

namespace App\Scanning;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The host-wide limit on suites running at once. Every tests install on the
 * Mac asks for a slot here before it runs a suite; a slot is a session-level
 * Postgres advisory lock in a database they all share, so it is let go the
 * moment the worker holding it lets go — or dies.
 *
 * A worker runs one job at a time, so an instance holds at most one slot.
 */
class ScanSlots
{
    /** The advisory lock's first key: which pool. The second is the slot. */
    public const SCANS = 0x7E57;

    public const PULL_REQUESTS = 0x7E58;

    /** @var array{0: int, 1: int}|null */
    private ?array $held = null;

    public function __construct(private ?string $connection = null) {}

    /**
     * Take a free slot from the pool, or null when every one is held.
     */
    public function acquire(bool $pullRequest = false): ?int
    {
        $pool = $pullRequest ? self::PULL_REQUESTS : self::SCANS;

        if ($this->held !== null && $this->held[0] === $pool) {
            return $this->held[1];
        }

        $this->release();

        $size = max(1, (int) config($pullRequest ? 'scanner.slots.pull_requests' : 'scanner.slots.scans'));

        for ($slot = 1; $slot <= $size; $slot++) {
            $taken = $this->connection()->selectOne('select pg_try_advisory_lock(?, ?) as taken', [$pool, $slot]);

            if ($taken->taken ?? false) {
                $this->held = [$pool, $slot];

                return $slot;
            }
        }

        return null;
    }

    public function release(): void
    {
        if ($this->held === null) {
            return;
        }

        [$pool, $slot] = $this->held;
        $this->held = null;

        try {
            $this->connection()->selectOne('select pg_advisory_unlock(?, ?)', [$pool, $slot]);
        } catch (Throwable) {
            // A lost connection has already let the lock go.
        }
    }

    /**
     * Run $work holding a slot. False, without running it, when none is free.
     */
    public function holding(bool $pullRequest, callable $work): bool
    {
        if ($this->acquire($pullRequest) === null) {
            return false;
        }

        try {
            $work();
        } finally {
            $this->release();
        }

        return true;
    }

    private function connection(): Connection
    {
        return DB::connection($this->connection ?? (string) config('scanner.slots.connection'));
    }
}
