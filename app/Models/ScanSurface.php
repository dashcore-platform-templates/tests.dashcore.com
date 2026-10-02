<?php

namespace App\Models;

use App\Enums\MutationResult;
use App\Enums\SurfaceChange;
use App\Enums\SurfaceStatus;
use App\Enums\VerdictReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanSurface extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SurfaceStatus::class,
            'reason' => VerdictReason::class,
            'change' => SurfaceChange::class,
            'mutation' => MutationResult::class,
            'evidence' => 'array',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function surface(): BelongsTo
    {
        return $this->belongsTo(Surface::class);
    }
}
