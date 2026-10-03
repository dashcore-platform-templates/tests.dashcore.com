<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing journeys have reached in an app: a page ("GET /leads/{n}"), a
 * Livewire call ("leads.show@logActivity") or a component on screen.
 */
class SimulationReach extends Model
{
    public const PAGE = 'page';

    public const CALL = 'call';

    public const MOUNT = 'mount';

    protected $table = 'simulation_reach';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'first_reached_at' => 'datetime',
            'last_reached_at' => 'datetime',
        ];
    }

    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(SimulationRun::class, 'last_run_id');
    }
}
