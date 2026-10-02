<?php

namespace Database\Factories;

use App\Enums\SurfaceKind;
use App\Models\FleetApp;
use App\Models\Surface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Surface>
 */
class SurfaceFactory extends Factory
{
    public function definition(): array
    {
        $uri = fake()->unique()->slug(2);

        return [
            'fleet_app_id' => FleetApp::factory(),
            'kind' => SurfaceKind::Route,
            'identifier' => 'GET '.$uri,
            'label' => 'GET /'.$uri,
        ];
    }
}
