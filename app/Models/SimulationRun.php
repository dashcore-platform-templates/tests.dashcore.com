<?php

namespace App\Models;

use App\Enums\SimulationStatus;
use Database\Factories\SimulationRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One journey, walked once by one persona in a real browser.
 */
class SimulationRun extends Model
{
    /** @use HasFactory<SimulationRunFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SimulationStatus::class,
            'screenshots_pruned' => 'boolean',
            'reached' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(SimulationStep::class)->orderBy('position');
    }

    public function personaFirstName(): string
    {
        return strtok($this->persona_name, ' ') ?: $this->persona_name;
    }

    /**
     * The step that failed, if any.
     */
    public function failedStep(): ?SimulationStep
    {
        return $this->steps->firstWhere('status', SimulationStatus::Failed);
    }

    /**
     * The newest step with a picture: what the browser shows right now, or
     * showed when the run ended.
     */
    public function latestScreenshotStep(): ?SimulationStep
    {
        return $this->steps->whereNotNull('screenshot')->sortByDesc('position')->first();
    }
}
