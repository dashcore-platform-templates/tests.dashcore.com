<?php

namespace Tests\Feature\Workflows;

use App\Enums\SimulationStatus;
use App\Enums\WorkflowFrequency;
use App\Livewire\Workflows\Index;
use App\Livewire\Workflows\Show;
use App\Livewire\Workflows\Wizard;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get(route('workflows.index'))->assertRedirect(route('login'));
    }

    public function test_members_cannot_see_workflows(): void
    {
        $workflow = Workflow::factory()->create();
        $member = User::factory()->create();

        $this->actingAs($member)->get(route('workflows.index'))->assertForbidden();
        $this->actingAs($member)->get(route('workflows.show', $workflow))->assertForbidden();
        $this->actingAs($member)->get(route('workflows.create'))->assertForbidden();
    }

    public function test_an_admin_sees_an_empty_list_and_it_is_in_the_navigation(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('workflows.index'))
            ->assertOk()
            ->assertSee('No workflows yet')
            ->assertSee(route('workflows.index'));
    }

    public function test_the_list_shows_each_workflow_and_how_it_last_went(): void
    {
        $workflow = Workflow::factory()->create(['name' => 'Lets-talk reaches us', 'frequency' => WorkflowFrequency::Every15Minutes, 'last_status' => SimulationStatus::Failed, 'alerting' => true]);
        WorkflowRun::factory()->for($workflow)->create(['status' => SimulationStatus::Failed]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('workflows.index'))
            ->assertOk()
            ->assertSee('Lets-talk reaches us')
            ->assertSee('Every 15 minutes')
            ->assertSee('alert sent');
    }

    public function test_the_wizard_builds_a_workflow_and_switches_it_on(): void
    {
        $this->freezeSecond();
        $admin = User::factory()->admin()->create(['email' => 'admin@example.test']);

        $component = Livewire::actingAs($admin)->test(Wizard::class)
            ->assertSet('notify', 'admin@example.test')
            ->call('next')
            ->assertHasErrors(['name', 'baseUrl'])
            ->set('name', 'Lets-talk reaches us')
            ->set('baseUrl', 'https://dashcore.com/')
            ->call('next')
            ->assertSet('stage', 2)
            ->call('useContactFormTemplate')
            ->set('steps.0.path', '/lets-talk')
            ->set('steps.1.do', 'click')
            ->call('next')
            ->assertSet('stage', 3)
            ->set('frequency', WorkflowFrequency::Every15Minutes->value)
            ->set('notify', 'Admin@example.test, ops@example.test; admin@example.test')
            ->call('next')
            ->assertSet('stage', 4)
            ->assertSee('Open the contact page')
            ->call('save', true);

        $workflow = Workflow::query()->sole();
        $component->assertRedirect(route('workflows.show', $workflow));

        $this->assertSame('https://dashcore.com', $workflow->base_url);
        $this->assertTrue($workflow->active);
        $this->assertTrue($workflow->next_run_at->eq(now()));
        $this->assertSame(['admin@example.test', 'ops@example.test'], $workflow->notify);
        $this->assertSame($admin->id, $workflow->created_by);
        $this->assertSame(['do' => 'visit', 'label' => 'Open the contact page', 'path' => '/lets-talk'], $workflow->steps[0]);
        // A step switched from "Type" to "Click" keeps no stale value.
        $this->assertSame(['do' => 'click', 'label' => 'Type a first name', 'target' => '#first_name'], $workflow->steps[1]);
    }

    public function test_a_step_missing_what_it_needs_is_stopped_on_the_steps_stage(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())->test(Wizard::class)
            ->set('name', 'Broken')
            ->set('baseUrl', 'https://example.test')
            ->call('next')
            ->set('steps', [['do' => 'visit', 'label' => '', 'path' => ''], ['do' => 'fill', 'label' => '', 'target' => '#a']])
            ->call('next')
            ->assertHasErrors(['steps.0.path', 'steps.1.value'])
            ->assertSet('stage', 2);
    }

    public function test_an_address_that_is_not_an_email_is_refused(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())->test(Wizard::class)
            ->set('stage', 3)
            ->set('notify', 'ok@example.test, nope')
            ->call('next')
            ->assertHasErrors('notify');
    }

    public function test_steps_can_be_added_moved_and_removed(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())->test(Wizard::class)
            ->call('addStep', 'see')
            ->call('addStep', 'nonsense')
            ->assertCount('steps', 2)
            ->call('moveStep', 1, -1)
            ->assertSet('steps.0.do', 'see')
            ->call('removeStep', 0)
            ->assertSet('steps', [['do' => 'visit', 'label' => '', 'path' => '/']]);
    }

    public function test_saving_switched_off_leaves_it_off_with_no_turn_booked(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())->test(Wizard::class)
            ->set('name', 'Try first')
            ->set('baseUrl', 'https://example.test')
            ->call('save', false)
            ->assertHasNoErrors();

        $workflow = Workflow::query()->sole();
        $this->assertFalse($workflow->active);
        $this->assertNull($workflow->next_run_at);
    }

    public function test_changing_a_workflow_keeps_its_place_in_the_schedule(): void
    {
        $next = now()->addMinutes(7)->startOfSecond();
        $workflow = Workflow::factory()->create(['active' => true, 'next_run_at' => $next]);

        $this->actingAs(User::factory()->admin()->create())->get(route('workflows.edit', $workflow))->assertOk()->assertSee('Change workflow');

        Livewire::actingAs(User::factory()->admin()->create())->test(Wizard::class, ['workflow' => $workflow])
            ->assertSet('name', 'Contact form sends')
            ->set('name', 'Contact form still sends')
            ->call('save', false)
            ->assertHasNoErrors();

        $workflow->refresh();
        $this->assertSame('Contact form still sends', $workflow->name);
        $this->assertTrue($workflow->active);
        $this->assertTrue($workflow->next_run_at->eq($next));
    }

    public function test_switching_on_books_a_turn_now_and_off_clears_it(): void
    {
        $this->freezeSecond();
        $workflow = Workflow::factory()->create();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(Index::class)->call('toggle', $workflow->id);
        $this->assertTrue($workflow->refresh()->active);
        $this->assertTrue($workflow->next_run_at->eq(now()));

        Livewire::actingAs($admin)->test(Show::class, ['workflow' => $workflow])->call('toggle');
        $this->assertFalse($workflow->refresh()->active);
        $this->assertNull($workflow->next_run_at);
    }

    public function test_run_now_asks_the_schedule_for_a_walk(): void
    {
        $workflow = Workflow::factory()->create();

        Livewire::actingAs(User::factory()->admin()->create())->test(Show::class, ['workflow' => $workflow])
            ->call('runNow')
            ->assertSee('Waiting to start');

        $this->assertNotNull($workflow->refresh()->run_requested_at);
    }

    public function test_the_record_shows_a_run_step_by_step(): void
    {
        $workflow = Workflow::factory()->create();
        $run = WorkflowRun::factory()->for($workflow)->create(['status' => SimulationStatus::Failed, 'error' => 'Type an email: Timeout']);
        WorkflowRunStep::query()->create(['workflow_run_id' => $run->id, 'position' => 0, 'label' => 'Open the contact page', 'action' => 'visit', 'status' => SimulationStatus::Passed]);
        WorkflowRunStep::query()->create(['workflow_run_id' => $run->id, 'position' => 1, 'label' => 'Type an email', 'action' => 'fill', 'status' => SimulationStatus::Failed, 'error' => 'Timeout', 'screenshot' => '001.jpg']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('workflows.show', $workflow))
            ->assertOk()
            ->assertSee('Type an email: Timeout')
            ->assertSee('Open the contact page')
            ->assertSee(route('workflows.screenshot', [$run, '001.jpg']));
    }

    public function test_a_screenshot_is_served_only_while_it_exists(): void
    {
        $shots = sys_get_temp_dir().'/wf-'.bin2hex(random_bytes(4));
        config(['workflows.screenshots' => $shots]);
        $run = WorkflowRun::factory()->create();
        File::ensureDirectoryExists("{$shots}/{$run->id}");
        File::put("{$shots}/{$run->id}/000.jpg", 'jpeg');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('workflows.screenshot', [$run, '000.jpg']))->assertOk();
        $this->actingAs($admin)->get(route('workflows.screenshot', [$run, '001.jpg']))->assertNotFound();

        File::deleteDirectory($shots);
    }

    public function test_deleting_a_workflow_takes_its_runs_with_it(): void
    {
        $workflow = Workflow::factory()->create();
        $run = WorkflowRun::factory()->for($workflow)->create();

        Livewire::actingAs(User::factory()->admin()->create())->test(Show::class, ['workflow' => $workflow])
            ->call('delete')
            ->assertRedirect(route('workflows.index'));

        $this->assertModelMissing($workflow);
        $this->assertModelMissing($run);
    }

    public function test_members_cannot_act_on_a_workflow(): void
    {
        $workflow = Workflow::factory()->create();

        Livewire::actingAs(User::factory()->create())->test(Index::class)->call('runNow', $workflow->id)->assertForbidden();
        $this->assertNull($workflow->refresh()->run_requested_at);
    }

    public function test_a_switched_on_workflow_whose_turn_has_come_says_so_and_keeps_watching(): void
    {
        $workflow = Workflow::factory()->active()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('workflows.show', $workflow))
            ->assertOk()
            ->assertSee('next run due now')
            ->assertSee('wire:poll.5s', false);

        $workflow->update(['next_run_at' => now()->addHour()]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('workflows.show', $workflow))
            ->assertDontSee('due now')
            ->assertDontSee('wire:poll', false);
    }
}
