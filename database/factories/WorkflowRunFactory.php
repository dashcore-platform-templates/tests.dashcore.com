<?php

namespace Database\Factories;

use App\Enums\SimulationStatus;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowRun>
 */
class WorkflowRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'status' => SimulationStatus::Passed,
            'trigger' => WorkflowRun::TRIGGER_SCHEDULE,
            'steps_total' => 4,
            'steps_passed' => 4,
            'duration_ms' => 4200,
            'started_at' => now()->subSeconds(5),
            'finished_at' => now(),
        ];
    }
}
