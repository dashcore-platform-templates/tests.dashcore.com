<?php

namespace Database\Factories;

use App\Enums\JourneyStatus;
use App\Models\SimulationJourney;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimulationJourney>
 */
class SimulationJourneyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => 'sales.'.$this->faker->unique()->slug(3),
            'target' => 'sales',
            'name' => 'Log a note on a lead',
            'why' => 'leads.show@saveNotes is never reached.',
            'roles' => ['Sales rep'],
            'weight' => 1,
            'steps' => [
                ['do' => 'visit', 'label' => '[[who]] opens the leads list', 'path' => '/leads'],
                ['do' => 'see', 'label' => '[[who]] sees the list', 'text' => 'Leads'],
            ],
            'summary' => '[[who]] looked at the leads',
            'status' => JourneyStatus::Trial,
        ];
    }
}
