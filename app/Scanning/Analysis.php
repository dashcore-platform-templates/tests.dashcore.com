<?php

namespace App\Scanning;

use App\Enums\SurfaceStatus;
use App\Enums\VerdictReason;
use App\Scanning\Inventory\DiscoveredSurface;

/**
 * The verdict for every surface in one scan.
 */
final readonly class Analysis
{
    /**
     * @param  array<string, array{surface: DiscoveredSurface, status: SurfaceStatus, reason: ?VerdictReason, tests: list<string>, proving: list<string>, count: int}>  $results  keyed by DiscoveredSurface::key()
     * @param  array<string, int>  $testSurfaceCounts  surfaces each test reached, by test id
     */
    public function __construct(
        public array $results,
        public array $testSurfaceCounts,
    ) {}

    /**
     * Downgrade a surface whose tests let a mutant survive.
     */
    public function withSurvivor(string $key): self
    {
        $results = $this->results;
        $results[$key]['status'] = SurfaceStatus::Weak;
        $results[$key]['reason'] = VerdictReason::MutantSurvived;

        return new self($results, $this->testSurfaceCounts);
    }

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
