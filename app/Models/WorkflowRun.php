<?php

namespace App\Models;

use App\Enums\SimulationStatus;
use Database\Factories\WorkflowRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One walk of a workflow in a real browser.
 */
class WorkflowRun extends Model
{
    /** @use HasFactory<WorkflowRunFactory> */
    use HasFactory;

    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_MANUAL = 'manual';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SimulationStatus::class,
            'screenshots_pruned' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowRunStep::class)->orderBy('position');
    }

    public function failedStep(): ?WorkflowRunStep
    {
        return $this->steps->firstWhere('status', SimulationStatus::Failed);
    }
}
