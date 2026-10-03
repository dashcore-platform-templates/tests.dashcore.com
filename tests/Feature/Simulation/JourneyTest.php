<?php

namespace Tests\Feature\Simulation;

use App\Simulation\Journey;
use App\Simulation\Persona;
use App\Simulation\Simulation;
use Faker\Factory as Faker;
use Tests\TestCase;

class JourneyTest extends TestCase
{
    /**
     * The step kinds simulator/run.mjs knows, read from its switch.
     *
     * @return list<string>
     */
    private function browserStepKinds(): array
    {
        preg_match_all("/case '([a-z]+)':/", file_get_contents(base_path('simulator/run.mjs')), $matches);

        return $matches[1];
    }

    /**
     * @return list<Journey>
     */
    private function journeys(): array
    {
        return array_column(app(Simulation::class)->journeys(), 'journey');
    }

    public function test_every_journey_signs_in_as_its_persona_and_only_uses_steps_the_browser_knows(): void
    {
        foreach ($this->journeys() as $journey) {
            $this->assertJourneyIsWellFormed($journey);
        }
    }

    private function assertJourneyIsWellFormed(Journey $journey): void
    {
        $persona = new Persona('Priya Natarajan', 'priya@example.test', 'secret', 'Sales rep');
        $script = $journey->script($persona, fake());

        $this->assertArrayHasKey($journey->target(), config('simulation.targets'));
        $this->assertNotEmpty($script->steps());
        $this->assertSame(['do' => 'visit', 'label' => 'Priya opens the login page', 'path' => '/login'], $script->steps()[0]);
        $this->assertContains('priya@example.test', array_column($script->steps(), 'value'));
        $this->assertStringStartsWith('Priya ', $script->getSummary());

        foreach ($script->steps() as $step) {
            $this->assertContains($step['do'], $this->browserStepKinds(), "Step \"{$step['label']}\" uses a kind run.mjs does not know.");
            $this->assertLessThanOrEqual(250, mb_strlen($step['label']));
        }
    }

    public function test_every_run_types_a_fresh_contact(): void
    {
        $persona = new Persona('Priya Natarajan', 'priya@example.test', 'secret', 'Sales rep');

        foreach ($this->journeys() as $journey) {
            $emails = fn () => collect($journey->script($persona, Faker::create())->steps())
                ->where('target', '#newEmail')->pluck('value')->all();

            $this->assertNotSame($emails(), $emails(), $journey->key());
        }
    }

    public function test_journey_keys_are_unique(): void
    {
        $this->assertCount(count(config('simulation.journeys')), app(Simulation::class)->journeys());
    }
}
