<?php

namespace App\Livewire\Workflows;

use App\Enums\WorkflowFrequency;
use App\Models\Workflow;
use App\Services\PermissionService;
use App\Workflows\Steps;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Builds a workflow, or changes one, in four steps: where it starts, what
 * it does, how often and who hears, then a last look before it is saved —
 * switched on, or left off to try by hand first.
 */
class Wizard extends Component
{
    public const STAGES = [1 => 'Where', 2 => 'Steps', 3 => 'Schedule', 4 => 'Review'];

    public ?int $workflowId = null;

    public int $stage = 1;

    public string $name = '';

    public string $description = '';

    public string $baseUrl = '';

    /** @var list<array<string, string>> */
    public array $steps = [];

    public string $frequency = WorkflowFrequency::Hourly->value;

    public string $notify = '';

    public int $alertAfter = 2;

    public function mount(?Workflow $workflow = null): void
    {
        abort_unless($this->canManage(), 403);

        if ($workflow?->exists) {
            $this->workflowId = $workflow->id;
            $this->name = $workflow->name;
            $this->description = (string) $workflow->description;
            $this->baseUrl = $workflow->base_url;
            $this->steps = $workflow->steps;
            $this->frequency = $workflow->frequency->value;
            $this->notify = implode(', ', $workflow->notify ?? []);
            $this->alertAfter = $workflow->alert_after;

            return;
        }

        $this->notify = (string) auth()->user()->email;
        $this->steps = [['do' => 'visit', 'label' => '', 'path' => '/']];
    }

    public function next(): void
    {
        $this->validateStage($this->stage);
        $this->stage = min(4, $this->stage + 1);
    }

    public function back(): void
    {
        $this->stage = max(1, $this->stage - 1);
    }

    public function goTo(int $stage): void
    {
        // Forward only through stages that are filled in properly.
        foreach (range(1, max(1, min(4, $stage)) - 1) as $earlier) {
            $this->validateStage($earlier);
        }

        $this->stage = max(1, min(4, $stage));
    }

    public function addStep(string $kind = 'click'): void
    {
        if (! array_key_exists($kind, Steps::KINDS) || count($this->steps) >= 60) {
            return;
        }

        $this->steps[] = ['do' => $kind, 'label' => ''];
    }

    public function removeStep(int $index): void
    {
        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);
    }

    public function moveStep(int $index, int $by): void
    {
        $to = $index + $by;

        if (! isset($this->steps[$index], $this->steps[$to])) {
            return;
        }

        [$this->steps[$index], $this->steps[$to]] = [$this->steps[$to], $this->steps[$index]];
    }

    /**
     * Start the steps from a pattern most sites have: a contact form that
     * thanks you once sent. Selectors are guesses to be corrected.
     */
    public function useContactFormTemplate(): void
    {
        $this->steps = [
            ['do' => 'visit', 'label' => 'Open the contact page', 'path' => '/contact'],
            ['do' => 'fill', 'label' => 'Type a first name', 'target' => '#first_name', 'value' => '[[first]]'],
            ['do' => 'fill', 'label' => 'Type a last name', 'target' => '#last_name', 'value' => '[[last]]'],
            ['do' => 'fill', 'label' => 'Type an email', 'target' => '#email', 'value' => '[[email]]'],
            ['do' => 'fill', 'label' => 'Type a company', 'target' => '#company', 'value' => '[[company]]'],
            ['do' => 'fill', 'label' => 'Say what they need', 'target' => '#message', 'value' => 'A test message from tests.dashcore.com.'],
            ['do' => 'click', 'label' => 'Send the form', 'target' => 'button[type=submit]'],
            ['do' => 'see', 'label' => 'The site says thank you', 'text' => 'Thank you'],
        ];
    }

    public function save(bool $switchOn = false): void
    {
        abort_unless($this->canManage(), 403);

        foreach (array_keys(self::STAGES) as $stage) {
            try {
                $this->validateStage($stage);
            } catch (ValidationException $e) {
                $this->stage = $stage;

                throw $e;
            }
        }

        $workflow = DB::transaction(function () use ($switchOn) {
            $workflow = $this->workflowId
                ? Workflow::query()->lockForUpdate()->findOrFail($this->workflowId)
                : new Workflow(['created_by' => auth()->id()]);

            $workflow->fill([
                'name' => trim($this->name),
                'description' => trim($this->description) ?: null,
                'base_url' => rtrim(trim($this->baseUrl), '/'),
                'steps' => Steps::clean($this->steps),
                'frequency' => WorkflowFrequency::from($this->frequency),
                'notify' => $this->notifyList(),
                'alert_after' => $this->alertAfter,
            ]);

            if ($switchOn) {
                $workflow->active = true;
            }

            // A schedule change takes effect now: switched on, the next
            // turn is counted from now — or straight away for a new one.
            $workflow->next_run_at = $workflow->active && $workflow->frequency->minutes() !== null
                ? ($workflow->exists && ! $workflow->isDirty('frequency') && $workflow->next_run_at ? $workflow->next_run_at : now())
                : null;

            $workflow->save();

            return $workflow;
        });

        session()->flash('workflow-notice', $switchOn ? 'Saved and switched on. Its first run starts within a minute.' : 'Saved.');

        $this->redirectRoute('workflows.show', ['workflow' => $workflow], navigate: true);
    }

    public function render()
    {
        return view('livewire.workflows.wizard', [
            'kinds' => Steps::KINDS,
            'tokens' => Steps::TOKENS,
            'frequencies' => WorkflowFrequency::cases(),
        ]);
    }

    private function validateStage(int $stage): void
    {
        match ($stage) {
            1 => $this->validate([
                'name' => ['required', 'string', 'max:120'],
                'description' => ['nullable', 'string', 'max:1000'],
                'baseUrl' => ['required', 'url:http,https', 'max:255'],
            ], attributes: ['baseUrl' => 'start URL']),
            2 => $this->validate(Steps::rules('steps'), [
                'required_if' => 'This step needs this.',
            ]),
            3 => $this->validate([
                'frequency' => ['required', Rule::enum(WorkflowFrequency::class)],
                'notify' => ['nullable', 'string', 'max:1000', function (string $attribute, mixed $value, \Closure $fail) {
                    foreach ($this->notifyList() as $email) {
                        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $fail("\"{$email}\" is not an email address.");
                        }
                    }
                }],
                'alertAfter' => ['required', 'integer', 'min:1', 'max:20'],
            ], attributes: ['alertAfter' => 'failures before an alert']),
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function notifyList(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $email) => Str::lower(trim($email)),
            preg_split('/[\s,;]+/', $this->notify) ?: [],
        ))));
    }

    private function canManage(): bool
    {
        return app(PermissionService::class)->hasFullAccessForUser(auth()->user(), 'workflows');
    }
}
