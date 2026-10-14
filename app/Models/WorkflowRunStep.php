<?php

namespace App\Models;

use App\Enums\SimulationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a workflow run, as the browser reported it.
 */
class WorkflowRunStep extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SimulationStatus::class,
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }
}
