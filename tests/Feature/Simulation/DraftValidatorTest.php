<?php

namespace Tests\Feature\Simulation;

use App\Models\SimulationJourney;
use App\Simulation\Exploring\DraftValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftValidatorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function draft(array $overrides = []): array
    {
        return [
            'key' => 'save-lead-notes',
            'name' => 'Save notes on a lead',
            'why' => 'leads.show@saveNotes is never reached.',
            'roles' => ['Sales rep'],
            'weight' => 2,
            'summary' => '[[who]] wrote notes on {lead}',
            'steps' => [
                ['do' => 'visit', 'label' => '[[who]] opens the leads list', 'path' => '/leads'],
                ['do' => 'remember', 'label' => '[[who]] picks the first lead', 'target' => 'tbody tr:first-child a', 'as' => 'lead'],
                ['do' => 'click', 'label' => '[[who]] opens {lead}', 'target' => 'tbody tr:first-child a'],
                ['do' => 'fill', 'label' => '[[who]] writes notes', 'target' => 'textarea[wire\:model="notes"]', 'value' => '[[sentence]]'],
                ['do' => 'click', 'label' => '[[who]] saves them', 'target' => 'role=button[name="Save notes"]'],
                ['do' => 'see', 'label' => '[[who]] sees they were saved', 'text' => 'Notes saved.'],
            ],
            ...$overrides,
        ];
    }

    private function problems(array $draft): array
    {
        return app(DraftValidator::class)->problems($draft, 'sales');
    }

    public function test_a_sound_draft_may_go_on_trial(): void
    {
        $this->assertSame([], $this->problems($this->draft()));
    }

    public function test_drafts_that_would_break_the_rules_are_turned_away_with_the_reason(): void
    {
        $steps = $this->draft()['steps'];

        $this->assertContains('The key "Save Notes" is not a kebab-case slug.', $this->problems($this->draft(['key' => 'Save Notes'])));
        $this->assertContains('A journey called sales.capture-lead already exists.', $this->problems($this->draft(['key' => 'capture-lead'])));
        $this->assertContains('Nobody has the role "Auditor".', $this->problems($this->draft(['roles' => ['Auditor']])));
        $this->assertContains('It names no role to walk it.', $this->problems($this->draft(['roles' => []])));
        $this->assertContains('Step 1 visits "https://evil.example/": journeys stay in the app, so a path starts with /.', $this->problems($this->draft(['steps' => [['do' => 'visit', 'label' => 'x', 'path' => 'https://evil.example/'], ...array_slice($steps, 1)]])));
        $this->assertContains('Step 1 has an unknown kind "eval".', $this->problems($this->draft(['steps' => [['do' => 'eval', 'label' => 'x'], ...$steps]])));
        $this->assertContains('Step 1 (fill) has no value.', $this->problems($this->draft(['steps' => [['do' => 'fill', 'label' => 'x', 'target' => '#a'], ...$steps]])));
        $this->assertContains('Step 1 uses {lead} before any step remembers it.', $this->problems($this->draft(['steps' => [['do' => 'click', 'label' => 'open {lead}', 'target' => 'a'], ...$steps]])));
        $this->assertContains('Step 1 uses an unknown token [[password]].', $this->problems($this->draft(['steps' => [['do' => 'fill', 'label' => 'x', 'target' => '#a', 'value' => '[[password]]'], ...$steps]])));
        $this->assertContains('It must end by checking the outcome (see or gone), so a pass means the work landed.', $this->problems($this->draft(['steps' => array_slice($steps, 0, 5)])));
        $this->assertContains('It has 1 steps; a journey takes 2 to 40.', $this->problems($this->draft(['steps' => [$steps[5]]])));
    }

    public function test_a_key_already_drafted_is_taken(): void
    {
        SimulationJourney::factory()->create(['key' => 'sales.save-lead-notes']);

        $this->assertContains('A journey called sales.save-lead-notes already exists.', $this->problems($this->draft()));
    }
}
