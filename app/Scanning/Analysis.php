<?php

namespace App\Scanning;

use App\Enums\SurfaceStatus;
use App\Scanning\Inventory\DiscoveredSurface;

/**
 * The verdict for every surface in one scan.
 */
final readonly class Analysis
{
    /**
     * @param  array<string, array{surface: DiscoveredSurface, status: SurfaceStatus, tests: list<string>, count: int}>  $results  keyed by DiscoveredSurface::key()
     * @param  array<string, int>  $testSurfaceCounts  surfaces each test reached, by test id
     */
    public function __construct(
        public array $results,
        public array $testSurfaceCounts,
    ) {}

    public function count(SurfaceStatus $status): int
    {
        return count(array_filter($this->results, fn (array $r) => $r['status'] === $status));
    }

    public function score(): ?float
    {
        $total = count($this->results);

        return $total === 0 ? null : round($this->count(SurfaceStatus::Tested) / $total * 100, 2);
    }
}
