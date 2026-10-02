<?php

namespace Database\Factories;

use App\Enums\ScanStatus;
use App\Models\FleetApp;
use App\Models\Scan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scan>
 */
class ScanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fleet_app_id' => FleetApp::factory(),
            'status' => ScanStatus::Queued,
        ];
    }

    public function completed(float $score = 50.0): static
    {
        return $this->state(fn () => [
            'status' => ScanStatus::Completed,
            'commit_sha' => fake()->sha1(),
            'ref' => 'origin/main',
            'framework' => 'pest',
            'tests_total' => 10,
            'tests_passed' => 10,
            'surfaces_total' => 10,
            'surfaces_tested' => (int) round($score / 10),
            'surfaces_missing' => 10 - (int) round($score / 10),
            'score' => $score,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ]);
    }
}
