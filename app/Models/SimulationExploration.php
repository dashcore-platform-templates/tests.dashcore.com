<?php

namespace App\Models;

use App\Enums\ExplorationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SimulationExploration extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ExplorationStatus::class,
            'cost_usd' => 'decimal:4',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function journeys(): HasMany
    {
        return $this->hasMany(SimulationJourney::class, 'exploration_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
