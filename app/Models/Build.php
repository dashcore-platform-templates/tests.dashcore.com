<?php

namespace App\Models;

use App\Enums\BuildKind;
use App\Enums\BuildStatus;
use Database\Factories\BuildFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to close an app's gaps: an agent writes tests for a handful of
 * surfaces on a branch, this app verifies them independently, and a PR is
 * opened for review only if they hold up.
 *
 * `targets` is the list of surface ids the build was asked to cover;
 * `verification` is this app's own verdict for each, never the agent's.
 */
class Build extends Model
{
    /** @use HasFactory<BuildFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => BuildKind::class,
            'status' => BuildStatus::class,
            'targets' => 'array',
            'verification' => 'array',
            'changed_files' => 'array',
            'agent_report' => 'array',
            'notes' => 'array',
            'cost_usd' => 'decimal:4',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function fleetApp(): BelongsTo
    {
        return $this->belongsTo(FleetApp::class);
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @param  Builder<Build>  $query */
    public function scopeHoldingTargets(Builder $query): void
    {
        $query->whereIn('status', array_map(
            fn (BuildStatus $s) => $s->value,
            array_filter(BuildStatus::cases(), fn (BuildStatus $s) => $s->holdsTargets()),
        ));
    }
}
