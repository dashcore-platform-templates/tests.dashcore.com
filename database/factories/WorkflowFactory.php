<?php

namespace Database\Factories;

use App\Enums\WorkflowFrequency;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Contact form sends',
            'base_url' => 'https://site.example.test',
            'steps' => [
                ['do' => 'visit', 'path' => '/contact', 'label' => 'Open the contact page'],
                ['do' => 'fill', 'target' => '#email', 'value' => '[[email]]', 'label' => 'Type an email'],
                ['do' => 'click', 'target' => '#send', 'label' => 'Send the form'],
                ['do' => 'see', 'text' => 'Thanks', 'label' => 'See the thank-you'],
            ],
            'frequency' => WorkflowFrequency::Hourly,
            'active' => false,
            'notify' => ['owner@example.test'],
            'alert_after' => 2,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['active' => true, 'next_run_at' => now()->subMinute()]);
    }
}
