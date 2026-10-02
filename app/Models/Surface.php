<?php

namespace App\Models;

use App\Enums\SurfaceKind;
use App\Enums\SurfaceStatus;
use Database\Factories\SurfaceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something in an app a test suite owes coverage to: a route, a Livewire
 * action, a job, a policy method. `status` is the latest scan's verdict;
 * the per-scan history lives in scan_surfaces.
 */
class Surface extends Model
{
    /** @use HasFactory<SurfaceFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => SurfaceKind::class,
            'status' => SurfaceStatus::class,
            'meta' => 'array',
            'removed_at' => 'datetime',
        ];
    }

    public function fleetApp(): BelongsTo
    {
        return $this->belongsTo(FleetApp::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ScanSurface::class);
    }

    /** @param  Builder<Surface>  $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('removed_at');
    }
}
