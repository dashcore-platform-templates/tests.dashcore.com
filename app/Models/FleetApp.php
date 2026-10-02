<?php

namespace App\Models;

use App\Enums\ScanStatus;
use Database\Factories\FleetAppFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One app in this install's fleet, as the control plane's manifest names it.
 * An app that leaves the fleet is soft-deleted so its scan history survives,
 * and restored if it comes back.
 */
class FleetApp extends Model
{
    /** @use HasFactory<FleetAppFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    public function surfaces(): HasMany
    {
        return $this->hasMany(Surface::class);
    }

    public function latestScan(): HasOne
    {
        return $this->hasOne(Scan::class)->latestOfMany();
    }

    public function latestCompletedScan(): HasOne
    {
        return $this->hasOne(Scan::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('status', ScanStatus::Completed),
        );
    }

    public function hasActiveScan(): bool
    {
        return $this->scans()
            ->whereIn('status', array_map(fn (ScanStatus $s) => $s->value, array_filter(ScanStatus::cases(), fn (ScanStatus $s) => $s->isActive())))
            ->exists();
    }

    /**
     * Whether this machine holds a checkout the scanner can run.
     */
    public function isScannable(): bool
    {
        return $this->is_enabled
            && $this->local_path !== null
            && is_file($this->local_path.'/artisan')
            && is_dir($this->local_path.'/.git');
    }
}
