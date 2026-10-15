<?php

namespace App\Models;

use App\Enums\WorkflowEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in the workflows' log. It keeps the workflow's name, so the
 * line still reads after the workflow is deleted.
 */
class WorkflowEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => WorkflowEventType::class,
            'created_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
