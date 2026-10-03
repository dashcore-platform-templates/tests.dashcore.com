<?php

namespace Database\Factories;

use App\Enums\SimulationStatus;
use App\Models\SimulationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimulationRun>
 */
class SimulationRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'journey' => 'sales.capture-lead',
            'journey_name' => 'Capture a lead',
            'target' => 'sales',
            'persona_name' => 'Maya Okafor',
            'persona_email' => 'maya.okafor@example.com',
            'status' => SimulationStatus::Passed,
            'summary' => 'Maya added lead Dana Fox from Fox Rail',
            'steps_total' => 3,
            'steps_passed' => 3,
            'duration_ms' => 4200,
            'started_at' => now()->subSeconds(5),
            'finished_at' => now(),
        ];
    }

    public function running(int $at = 0): static
    {
        return $this->state(fn () => [
            'status' => SimulationStatus::Running,
            'current_step' => $at,
            'steps_passed' => $at,
            'duration_ms' => null,
            'finished_at' => null,
        ]);
    }

    public function failed(string $error = 'Timed out waiting for "Lead added."'): static
    {
        return $this->state(fn () => [
            'status' => SimulationStatus::Failed,
            'steps_passed' => 2,
            'error' => $error,
        ]);
    }
}
