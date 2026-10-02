<?php

namespace Database\Factories;

use App\Models\FleetApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FleetApp>
 */
class FleetAppFactory extends Factory
{
    public function definition(): array
    {
        $key = fake()->unique()->word();

        return [
            'key' => $key,
            'host' => $key.'.dashcore.com',
            'name' => ucfirst($key),
            'group' => 'Domain service',
            'repo' => 'dashcoretech/'.$key.'.dashcore.com',
            'local_path' => null,
            'is_enabled' => true,
            'synced_at' => now(),
        ];
    }
}
