<?php

namespace App\Workflows;

use App\Enums\WorkflowEventType;
use App\Enums\WorkflowFrequency;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workflows as a file: exported to move them to another install or keep a
 * copy, imported back as new workflows. Only what a workflow *is* travels —
 * never its runs, its state or who made it — and an imported one always
 * arrives switched off, to be tried by hand before it runs on its own.
 */
class Transfer
{
    public const FORMAT = 'dashcore-workflows';

    public const VERSION = 1;

    public const MAX_WORKFLOWS = 100;

    /**
     * @param  iterable<Workflow>  $workflows
     * @return array{format: string, version: int, exported_at: string, workflows: list<array<string, mixed>>}
     */
    public static function export(iterable $workflows): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'workflows' => collect($workflows)->map(fn (Workflow $workflow) => [
                'name' => $workflow->name,
                'description' => $workflow->description,
                'base_url' => $workflow->base_url,
                'steps' => $workflow->steps,
                'frequency' => $workflow->frequency->value,
                'notify' => $workflow->notify ?? [],
                'alert_after' => $workflow->alert_after,
            ])->values()->all(),
        ];
    }

    /**
     * These workflows as a downloadable JSON file.
     *
     * @param  Collection<int, Workflow>  $workflows
     */
    public static function download(Collection $workflows): Response
    {
        return response(json_encode(self::export($workflows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.self::filename($workflows).'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * A file name for an export of these workflows.
     *
     * @param  Collection<int, Workflow>  $workflows
     */
    public static function filename(Collection $workflows): string
    {
        $what = $workflows->count() === 1 ? Str::slug($workflows->first()->name) ?: 'workflow' : 'workflows';

        return "{$what}-".now()->format('Y-m-d').'.json';
    }

    /**
     * Read an exported file and make a new, switched-off workflow from each
     * entry — all of them or none. A name already taken gets " (2)", " (3)"…
     *
     * @return Collection<int, Workflow>
     *
     * @throws ValidationException naming what is wrong, keyed `file`
     */
    public static function import(string $json, ?User $by = null, ?string $source = null): Collection
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw ValidationException::withMessages(['file' => 'This is not a workflows export file.']);
        }

        if (($data['version'] ?? null) !== self::VERSION) {
            throw ValidationException::withMessages(['file' => 'This export was made by a different version and cannot be read here.']);
        }

        $validator = Validator::make($data, [
            'workflows' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_WORKFLOWS],
            'workflows.*' => ['array'],
            'workflows.*.name' => ['required', 'string', 'max:120'],
            'workflows.*.description' => ['nullable', 'string', 'max:1000'],
            'workflows.*.base_url' => ['required', 'string', 'url:http,https', 'max:255'],
            'workflows.*.frequency' => ['required', 'string', Rule::enum(WorkflowFrequency::class)],
            'workflows.*.notify' => ['nullable', 'array', 'max:50'],
            'workflows.*.notify.*' => ['string', 'email'],
            'workflows.*.alert_after' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $validator->after(function ($validator) use ($data) {
            foreach ((array) ($data['workflows'] ?? []) as $i => $entry) {
                $steps = Validator::make(['steps' => is_array($entry) ? ($entry['steps'] ?? null) : null], Steps::rules('steps'));

                if ($steps->fails()) {
                    $validator->errors()->add("workflows.{$i}.steps", $steps->errors()->first());
                }
            }
        });

        if ($validator->fails()) {
            throw ValidationException::withMessages(['file' => self::describe($validator->errors()->messages(), $data)]);
        }

        return DB::transaction(function () use ($data, $by, $source) {
            $taken = Workflow::query()->pluck('name')->map(fn (string $name) => Str::lower($name))->all();

            return collect($data['workflows'])->map(function (array $entry) use (&$taken, $by, $source) {
                $name = self::freeName(trim($entry['name']), $taken);
                $taken[] = Str::lower($name);

                $workflow = Workflow::query()->create([
                    'name' => $name,
                    'description' => trim((string) ($entry['description'] ?? '')) ?: null,
                    'base_url' => rtrim(trim($entry['base_url']), '/'),
                    'steps' => Steps::clean($entry['steps']),
                    'frequency' => WorkflowFrequency::from($entry['frequency']),
                    'notify' => array_values(array_unique(array_map(fn (string $email) => Str::lower(trim($email)), $entry['notify'] ?? []))),
                    'alert_after' => $entry['alert_after'] ?? 2,
                    'active' => false,
                    'created_by' => $by?->id,
                ]);

                EventLog::record(WorkflowEventType::Imported, $workflow, $source !== null
                    ? "Imported from {$source}, switched off."
                    : 'Imported from a file, switched off.');

                return $workflow;
            });
        });
    }

    /**
     * @param  list<string>  $taken  lower-cased names already in use
     */
    private static function freeName(string $name, array $taken): string
    {
        $candidate = $name;

        for ($n = 2; in_array(Str::lower($candidate), $taken, true); $n++) {
            $candidate = Str::limit($name, 112, '')." ({$n})";
        }

        return $candidate;
    }

    /**
     * The first problem, said about the workflow it is in.
     *
     * @param  array<string, list<string>>  $messages
     * @param  array<string, mixed>  $data
     */
    private static function describe(array $messages, array $data): string
    {
        $key = array_key_first($messages);
        $message = $messages[$key][0];

        if (preg_match('/^workflows\.(\d+)/', $key, $m)) {
            $name = $data['workflows'][$m[1]]['name'] ?? null;
            $which = is_string($name) && $name !== '' ? "\"{$name}\"" : 'Workflow '.($m[1] + 1);

            return "{$which}: ".str_replace($key, Str::afterLast($key, '.'), $message);
        }

        return $message;
    }
}
