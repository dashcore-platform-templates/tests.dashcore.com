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

        $checked = 0;

        foreach ($this->journeys() as $journey) {
            $emails = fn () => collect($journey->script($persona, Faker::create())->steps())
                ->where('target', '#newEmail')->pluck('value')->all();

            if ($emails() === []) {
                continue;
            }

            $this->assertNotSame($emails(), $emails(), $journey->key());
            $checked++;
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function test_every_journey_has_someone_to_walk_it(): void
    {
        foreach ($this->journeys() as $journey) {
            $persona = app(Simulation::class)->persona($journey->target(), $journey->roles());

            $this->assertContains(
                collect(config("simulation.targets.{$journey->target()}.personas"))->firstWhere('email', $persona->email)['role'],
                $journey->roles(),
                $journey->key(),
            );
        }
    }

    public function test_placeholders_only_use_names_the_journey_remembers_first(): void
    {
        $persona = new Persona('Priya Natarajan', 'priya@example.test', 'secret', 'Sales rep');

        foreach ($this->journeys() as $journey) {
            $script = $journey->script($persona, fake());
            $known = [];

            foreach ($script->steps() as $step) {
                $used = [];
                preg_match_all('/\{([a-z_]+)\}/', implode(' ', array_filter($step, 'is_string')), $used);

                foreach ($used[1] as $name) {
                    $this->assertContains($name, $known, "{$journey->key()}: \"{$step['label']}\" uses {{$name}} before it is remembered.");
                }

                if ($step['do'] === 'remember') {
                    $known[] = $step['as'];
                }
            }

            preg_match_all('/\{([a-z_]+)\}/', (string) $script->getSummary(), $used);
            $this->assertEmpty(array_diff($used[1], $known), "{$journey->key()}: the summary uses a name nothing remembers.");
        }
    }

    public function test_journey_keys_are_unique(): void
    {
        $this->assertCount(count(config('simulation.journeys')), app(Simulation::class)->journeys());
    }
}
