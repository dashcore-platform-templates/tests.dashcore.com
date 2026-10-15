<?php

namespace Tests\Feature\Workflows;

use App\Enums\SimulationStatus;
use App\Enums\WorkflowFrequency;
use App\Livewire\Workflows\Index;
use App\Models\User;
use App\Models\Workflow;
use App\Workflows\Transfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_exporting_all_downloads_what_each_workflow_is_and_nothing_of_its_state(): void
    {
        $this->freezeSecond();
        $workflow = Workflow::factory()->active()->create([
            'name' => 'Lets-talk reaches us',
            'description' => 'The form must work.',
            'frequency' => WorkflowFrequency::Every15Minutes,
            'last_status' => SimulationStatus::Failed,
            'consecutive_failures' => 3,
            'alerting' => true,
        ]);
        Workflow::factory()->create(['name' => 'Another one']);

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('workflows.export'));

        $response->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="workflows-'.now()->format('Y-m-d').'.json"');

        $data = json_decode($response->getContent(), true);
        $this->assertSame(Transfer::FORMAT, $data['format']);
        $this->assertSame(Transfer::VERSION, $data['version']);
        $this->assertSame(['Another one', 'Lets-talk reaches us'], array_column($data['workflows'], 'name'));
        $this->assertSame([
            'name' => 'Lets-talk reaches us',
            'description' => 'The form must work.',
            'base_url' => 'https://site.example.test',
            'steps' => $workflow->steps,
            'frequency' => '15m',
            'notify' => ['owner@example.test'],
            'alert_after' => 2,
        ], $data['workflows'][1]);
    }

    public function test_one_workflow_exports_on_its_own_named_after_it(): void
    {
        $this->freezeSecond();
        $workflow = Workflow::factory()->create(['name' => 'Lets-talk reaches us']);
        Workflow::factory()->create(['name' => 'Not this one']);

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('workflows.export-one', $workflow));

        $response->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="lets-talk-reaches-us-'.now()->format('Y-m-d').'.json"');
        $this->assertSame(['Lets-talk reaches us'], array_column(json_decode($response->getContent(), true)['workflows'], 'name'));
    }

    public function test_members_cannot_export(): void
    {
        $workflow = Workflow::factory()->create();
        $member = User::factory()->create();

        $this->actingAs($member)->get(route('workflows.export'))->assertForbidden();
        $this->actingAs($member)->get(route('workflows.export-one', $workflow))->assertForbidden();
    }

    public function test_an_export_imports_back_as_new_workflows_switched_off(): void
    {
        $original = Workflow::factory()->active()->create(['name' => 'Lets-talk reaches us', 'frequency' => WorkflowFrequency::Daily, 'notify' => ['a@example.test', 'b@example.test'], 'alert_after' => 4]);
        $json = json_encode(Transfer::export([$original]));
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(Index::class)
            ->set('file', UploadedFile::fake()->createWithContent('workflows.json', $json))
            ->assertHasNoErrors()
            ->assertSet('file', null)
            ->assertSee('Imported "Lets-talk reaches us (2)". It is switched off');

        $copy = Workflow::query()->where('name', 'Lets-talk reaches us (2)')->sole();
        $this->assertFalse($copy->active);
        $this->assertNull($copy->next_run_at);
        $this->assertSame($admin->id, $copy->created_by);
        $this->assertEquals($original->steps, $copy->steps);
        $this->assertSame(WorkflowFrequency::Daily, $copy->frequency);
        $this->assertSame(['a@example.test', 'b@example.test'], $copy->notify);
        $this->assertSame(4, $copy->alert_after);
        $this->assertSame(0, $copy->runs()->count());
    }

    public function test_names_already_taken_are_numbered_on_import(): void
    {
        Workflow::factory()->create(['name' => 'Contact form sends']);
        Workflow::factory()->create(['name' => 'Contact form sends (2)']);

        $imported = Transfer::import(json_encode(Transfer::export(Workflow::factory()->count(2)->make(['name' => 'contact form sends']))));

        $this->assertSame(['contact form sends (3)', 'contact form sends (4)'], $imported->pluck('name')->all());
    }

    public function test_a_file_that_is_not_an_export_is_refused(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())->test(Index::class)
            ->set('file', UploadedFile::fake()->createWithContent('workflows.json', '{"hello": "world"}'))
            ->assertHasErrors(['file'])
            ->assertSee('This is not a workflows export file.');

        $this->assertSame(0, Workflow::query()->count());
    }

    public function test_a_file_that_is_not_json_is_refused(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())->test(Index::class)
            ->set('file', UploadedFile::fake()->create('workflows.png', 10, 'image/png'))
            ->assertHasErrors(['file']);

        $this->assertSame(0, Workflow::query()->count());
    }

    public function test_one_bad_workflow_imports_none_and_says_which(): void
    {
        $data = Transfer::export(Workflow::factory()->count(2)->make());
        $data['workflows'][1]['name'] = 'Broken one';
        $data['workflows'][1]['steps'][] = ['do' => 'fill', 'target' => '#name'];

        Livewire::actingAs(User::factory()->admin()->create())->test(Index::class)
            ->set('file', UploadedFile::fake()->createWithContent('workflows.json', json_encode($data)))
            ->assertHasErrors(['file'])
            ->assertSee('Could not import: &quot;Broken one&quot;:', false);

        $this->assertSame(0, Workflow::query()->count());
    }

    public function test_a_step_kind_the_runner_does_not_know_is_refused(): void
    {
        $data = Transfer::export(Workflow::factory()->count(1)->make());
        $data['workflows'][0]['steps'] = [['do' => 'eval', 'script' => 'alert(1)']];

        $this->expectException(ValidationException::class);

        Transfer::import(json_encode($data));
    }

    public function test_an_export_from_another_version_is_refused(): void
    {
        $data = Transfer::export(Workflow::factory()->count(1)->make());
        $data['version'] = 2;

        try {
            Transfer::import(json_encode($data));
            $this->fail('A version 2 export was imported.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('different version', $e->errors()['file'][0]);
        }
    }

    public function test_only_managers_may_import(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('workflows.index'))->assertSee('Import');

        $json = json_encode(Transfer::export(Workflow::factory()->count(1)->make()));

        Livewire::actingAs(User::factory()->create())->test(Index::class)
            ->set('file', UploadedFile::fake()->createWithContent('workflows.json', $json))
            ->assertForbidden();

        $this->assertSame(0, Workflow::query()->count());
    }
}
