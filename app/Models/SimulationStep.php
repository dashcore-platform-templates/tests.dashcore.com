<?php

namespace App\Models;

use App\Enums\SimulationStatus;
use Database\Factories\SimulationStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SimulationStep extends Model
{
    /** @use HasFactory<SimulationStepFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SimulationStatus::class,
            'console' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SimulationRun::class, 'simulation_run_id');
    }

    public function screenshotUrl(): ?string
    {
        if ($this->screenshot === null || $this->run?->screenshots_pruned) {
            return null;
        }

        return route('simulation.screenshot', ['run' => $this->simulation_run_id, 'file' => $this->screenshot]);
    }
}
