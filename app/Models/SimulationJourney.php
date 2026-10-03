<?php

namespace App\Models;

use App\Enums\JourneyStatus;
use Database\Factories\SimulationJourneyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A journey kept as data rather than code — drafted by the explorer.
 */
class SimulationJourney extends Model
{
    /** @use HasFactory<SimulationJourneyFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => JourneyStatus::class,
            'roles' => 'array',
            'steps' => 'array',
            'activated_at' => 'datetime',
        ];
    }

    public function exploration(): BelongsTo
    {
        return $this->belongsTo(SimulationExploration::class, 'exploration_id');
    }

    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(SimulationRun::class, 'last_run_id');
    }
}
