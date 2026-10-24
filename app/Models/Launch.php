<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One client's whole signup flown against a stand-in fleet by `tests:launch`
 * — every stage of bringing a new client on, in order — as the journey map
 * draws it. Each stage holds what `tests:journey --record` wrote: its walks,
 * step by step, with their screenshots, and what happened outside the
 * browser between them.
 */
class Launch extends Model
{
    public const LAUNCHED = 'launched';

    public const FAILED = 'failed';

    public const SCRUBBED = 'scrubbed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'countdown' => 'array',
            'stages' => 'array',
            'teardown' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function launched(): bool
    {
        return $this->outcome === self::LAUNCHED;
    }
}
