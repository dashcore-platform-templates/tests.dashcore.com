<?php

namespace App\Models;

use App\Enums\ScanStatus;
use Database\Factories\ScanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scan extends Model
{
    /** @use HasFactory<ScanFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ScanStatus::class,
            'score' => 'decimal:2',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'notes' => 'array',
        ];
    }

    public function fleetApp(): BelongsTo
    {
        return $this->belongsTo(FleetApp::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function surfaceResults(): HasMany
    {
        return $this->hasMany(ScanSurface::class);
    }

    public function tests(): HasMany
    {
        return $this->hasMany(ScanTest::class);
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }
}
