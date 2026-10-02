<?php

namespace Database\Factories;

use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\FleetApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Build>
 */
class BuildFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fleet_app_id' => FleetApp::factory(),
            'status' => BuildStatus::Queued,
            'targets' => [],
        ];
    }
}
