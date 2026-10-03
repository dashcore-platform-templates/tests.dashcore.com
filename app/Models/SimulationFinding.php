<?php

namespace App\Models;

use App\Enums\FindingKind;
use App\Enums\FindingStatus;
use Database\Factories\SimulationFindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the Proving Ground keeps running into: grouped by fingerprint,
 * counted, resolved once journeys stop hitting it, reopened if it returns.
 */
class SimulationFinding extends Model
{
    /** @use HasFactory<SimulationFindingFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => FindingKind::class,
            'status' => FindingStatus::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(SimulationRun::class, 'last_run_id');
    }

    /**
     * Back after having been resolved, and not yet resolved again.
     */
    public function isRegression(): bool
    {
        return $this->status === FindingStatus::Open && $this->reopened_at !== null;
    }
}
