<?php

namespace App\Models;

use App\Enums\TestOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One test of one app: where its code is, how it has fared scan after scan,
 * and how much of the app it proves.
 */
class LibraryTest extends Model
{
    /** Outcomes kept per test, newest last; they outlive pruned scans. */
    public const HISTORY = 30;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_outcome' => TestOutcome::class,
            'history' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function fleetApp(): BelongsTo
    {
        return $this->belongsTo(FleetApp::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(LibraryFile::class, 'library_file_id');
    }

    public function lastScan(): BelongsTo
    {
        return $this->belongsTo(Scan::class, 'last_scan_id');
    }

    /** @param  Builder<LibraryTest>  $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    public static function hashKey(string $key): string
    {
        return sha1($key);
    }

    /**
     * A test that has never run in any scan — written but never proven.
     */
    public function neverRan(): bool
    {
        return $this->last_outcome === null;
    }
}
