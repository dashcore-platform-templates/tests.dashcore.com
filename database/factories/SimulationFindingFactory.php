<?php

namespace Database\Factories;

use App\Enums\FindingKind;
use App\Enums\FindingStatus;
use App\Models\SimulationFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimulationFinding>
 */
class SimulationFindingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kind' => FindingKind::DeadSpace,
            'fingerprint' => 'sales.close-won-deal#'.$this->faker->unique()->numberBetween(0, 999),
            'target' => 'sales',
            'journey' => 'sales.close-won-deal',
            'position' => 23,
            'title' => 'No loading feedback when Maya marks the deal won',
            'status' => FindingStatus::Open,
            'occurrences' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
