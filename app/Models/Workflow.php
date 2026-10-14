<?php

namespace App\Models;

use App\Enums\SimulationStatus;
use App\Enums\WorkflowFrequency;
use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A browser test built here as a record: where it starts, the steps it
 * walks, how often, and who hears when it breaks.
 */
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'notify' => 'array',
            'frequency' => WorkflowFrequency::class,
            'last_status' => SimulationStatus::class,
            'active' => 'boolean',
            'alerting' => 'boolean',
            'next_run_at' => 'datetime',
            'run_requested_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class)->orderByDesc('id');
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(WorkflowRun::class)->latestOfMany();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Workflows whose turn has come: someone pressed "Run now" (which works
     * whether it is switched on or not), or it is on and its time is up.
     */
    public function scopeDue(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNotNull('run_requested_at')
            ->orWhere(fn (Builder $query) => $query->where('active', true)->where('next_run_at', '<=', now())));
    }

    /**
     * When it should walk next on its own, counted from now.
     */
    public function nextScheduledRun(): ?Carbon
    {
        $minutes = $this->frequency->minutes();

        return $this->active && $minutes !== null ? now()->addMinutes($minutes) : null;
    }

    /**
     * Switch it on (its first turn straight away) or off (no more turns;
     * a "Run now" already asked for still happens).
     */
    public function toggleActive(): void
    {
        $this->active = ! $this->active;
        $this->next_run_at = $this->active && $this->frequency->minutes() !== null ? now() : null;
        $this->save();
    }

    /**
     * Whether a walk has been asked for or is underway.
     */
    public function isBusy(): bool
    {
        return $this->run_requested_at !== null
            || $this->latestRun?->status === SimulationStatus::Running;
    }
}
