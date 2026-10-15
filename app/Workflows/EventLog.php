<?php

namespace App\Workflows;

use App\Enums\WorkflowEventType;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the workflows' log. A line that cannot be written is reported,
 * never allowed to break the run or the change it describes.
 */
class EventLog
{
    public static function record(WorkflowEventType $type, ?Workflow $workflow, string $message, ?WorkflowRun $run = null): ?WorkflowEvent
    {
        try {
            // Its own savepoint: inside a caller's transaction, a failed
            // insert must not abort the rest of the caller's work.
            return DB::transaction(fn () => WorkflowEvent::query()->create([
                'type' => $type,
                'workflow_id' => $workflow?->exists ? $workflow->id : null,
                'workflow_name' => $workflow?->name,
                'workflow_run_id' => $run?->id,
                'user_id' => auth()->id(),
                'message' => Str::limit($message, 2000),
            ]));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * What a save changed, in plain words: "the steps and the schedule".
     *
     * @param  list<string>  $fields  changed attribute names
     */
    public static function describeChanges(array $fields): string
    {
        $words = array_values(array_unique(array_filter(array_map(fn (string $field) => match ($field) {
            'name' => 'the name',
            'description' => 'the description',
            'base_url' => 'the start URL',
            'steps' => 'the steps',
            'frequency' => 'the schedule',
            'notify' => 'who hears',
            'alert_after' => 'when to alert',
            default => null,
        }, $fields))));

        return $words === [] ? 'Saved with no changes.' : 'Changed '.Str::of(implode(', ', $words))->replaceLast(', ', ' and ').'.';
    }
}
