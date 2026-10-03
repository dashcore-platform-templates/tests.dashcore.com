<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One test file of one app, as of the latest commit the library read.
 */
class LibraryFile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'changed_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function fleetApp(): BelongsTo
    {
        return $this->belongsTo(FleetApp::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(LibraryFileRevision::class)->latest('created_at')->latest('id');
    }

    public function tests(): HasMany
    {
        return $this->hasMany(LibraryTest::class)->orderBy('start_line');
    }

    /** @param  Builder<LibraryFile>  $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /**
     * Where it sits in the suite: Feature, Unit, Browser…
     */
    public function suite(): string
    {
        if (preg_match('#^packages/(?:[^/]+/)*?([^/]+)/tests/#', $this->path, $m)) {
            return 'Package: '.$m[1];
        }

        $parts = explode('/', $this->path);

        return count($parts) > 2 ? $parts[1] : 'Other';
    }
}
