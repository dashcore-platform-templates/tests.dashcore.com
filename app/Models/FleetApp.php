<?php

namespace App\Models;

use App\Enums\ScanKind;
use App\Enums\ScanStatus;
use Database\Factories\FleetAppFactory;
use Illuminate\Database\Eloquent\Builder;
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
            'is_demo' => 'boolean',
            'is_enabled' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Apps of the real fleet: `tests:demo`'s made-up apps are never synced,
     * scanned, built, reported or pruned.
     *
     * @param  Builder<FleetApp>  $query
     */
    public function scopeReal(Builder $query): void
    {
        $query->where('is_demo', false);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    public function builds(): HasMany
    {
        return $this->hasMany(Build::class);
    }

    public function surfaces(): HasMany
    {
        return $this->hasMany(Surface::class);
    }

    /**
     * The app's latest main scan. Pull request scans only report on a PR;
     * they never stand in for the app's own record.
     */
    public function latestScan(): HasOne
    {
        return $this->hasOne(Scan::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('kind', ScanKind::Main),
        );
    }

    public function latestCompletedScan(): HasOne
    {
        return $this->hasOne(Scan::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('kind', ScanKind::Main)->where('status', ScanStatus::Completed),
        );
    }

    public function pullRequestScans(): HasMany
    {
        return $this->hasMany(Scan::class)->where('kind', ScanKind::PullRequest);
    }

    public function hasActiveScan(): bool
    {
        return $this->scans()
            ->where('kind', ScanKind::Main)
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
