<?php

namespace Database\Factories;

use App\Enums\SimulationStatus;
use App\Models\SimulationRun;
use App\Models\SimulationStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimulationStep>
 */
class SimulationStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'simulation_run_id' => SimulationRun::factory(),
            'position' => 0,
            'label' => 'Maya opens the leads list',
            'action' => 'visit',
            'status' => SimulationStatus::Passed,
            'duration_ms' => 320,
            'url' => 'http://sales.fakecompany.com.test/leads',
            'screenshot' => '000.jpg',
        ];
    }
}
