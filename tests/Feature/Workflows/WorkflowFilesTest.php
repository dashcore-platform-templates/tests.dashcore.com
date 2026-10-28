<?php

namespace Tests\Feature\Workflows;

use App\Models\Workflow;
use App\Workflows\Transfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The workflow files kept in this repo (workflows/**.json) must always be
 * importable, so a broken one is caught here rather than on production.
 */
class WorkflowFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_workflow_file_in_the_repo_imports_cleanly(): void
    {
        $files = collect(File::allFiles(base_path('workflows')))->filter(fn ($file) => $file->getExtension() === 'json');

        $this->assertNotEmpty($files, 'No workflow files found under workflows/.');

        foreach ($files as $file) {
            $imported = Transfer::import($file->getContents(), source: $file->getFilename());

            $this->assertNotEmpty($imported, "{$file->getRelativePathname()} holds no workflows.");
            $imported->each(fn (Workflow $workflow) => $this->assertFalse($workflow->active));
        }
    }

    public function test_the_lets_talk_journey_stops_before_turnstile_and_send(): void
    {
        $workflow = Transfer::import(File::get(base_path('workflows/customer-journey/01-lets-talk.json')))->sole();

        $this->assertSame('https://dashcore.com', $workflow->base_url);
        $this->assertSame('/lets-talk', $workflow->steps[0]['path']);
        $this->assertSame([], array_values(array_filter($workflow->steps, fn (array $step) => in_array($step['do'], ['click', 'press', 'filled'], true))), 'It must not click, press or wait for a captcha token: a live send reaches real people.');
    }

    public function test_the_starter_workflows_import_switched_off_and_never_send_anything(): void
    {
        $files = collect(File::files(base_path('workflows/starter')))->filter(fn ($file) => $file->getExtension() === 'json')->sortBy(fn ($file) => $file->getFilename())->values();

        $this->assertCount(4, $files);

        $workflows = $files->map(fn ($file) => Transfer::import($file->getContents(), source: $file->getFilename())->sole())->keyBy(fn (Workflow $workflow) => $workflow->name);

        foreach ($workflows as $name => $workflow) {
            $this->assertFalse($workflow->active, $name);
            $this->assertContains($workflow->base_url, ['https://dashcore.com', 'http://sales.fakecompany.com.test'], "{$name} must point at live dashcore.com or the local sales stand-in");
        }

        $letsTalk = $workflows['Website: lets-talk form takes an enquiry'];
        $this->assertSame('/lets-talk', $letsTalk->steps[0]['path']);
        $this->assertSame([], array_values(array_filter($letsTalk->steps, fn (array $step) => in_array($step['do'], ['click', 'press', 'filled'], true))), 'It must stop before Turnstile and send.');

        $this->assertSame([], array_values(array_filter($workflows['Website: main pages answer']->steps, fn (array $step) => ! in_array($step['do'], ['visit', 'see'], true))), 'The pages workflow only reads.');

        foreach (['Sales stand-in: a rep signs in', 'Sales stand-in: the leads list opens'] as $name) {
            $this->assertSame('/login', $workflows[$name]->steps[0]['path']);
            $this->assertSame('Dashboard', $workflows[$name]->steps[4]['text']);
        }
    }

    public function test_the_command_imports_files_switched_off(): void
    {
        $this->artisan('tests:import-workflows', ['files' => [base_path('workflows/customer-journey/01-lets-talk.json')]])
            ->expectsOutputToContain('imported "Lets-talk form is ready"')
            ->assertSuccessful();

        $workflow = Workflow::query()->sole();
        $this->assertFalse($workflow->active);
        $this->assertNull($workflow->created_by);
    }

    public function test_the_command_says_which_file_is_wrong_and_fails(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'wf').'.json';
        file_put_contents($bad, '{"format": "something-else"}');

        $this->artisan('tests:import-workflows', ['files' => [$bad, '/nope/missing.json']])
            ->expectsOutputToContain('This is not a workflows export file.')
            ->expectsOutputToContain('/nope/missing.json: no such file.')
            ->assertFailed();

        @unlink($bad);
        $this->assertSame(0, Workflow::query()->count());
    }
}
