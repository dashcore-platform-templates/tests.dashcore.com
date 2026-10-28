<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One walk of a customer journey (`tests:journey`) — whether it held, and
 * whether the stand-ins it walked were on the releases their fleet runs
 * live. A walk on a drifted stand-in proves the stand-in, not what clients
 * use, so it is kept and shown as drifted.
 */
class JourneyRun extends Model
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const REFUSED = 'refused';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'drift' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function drifted(): bool
    {
        return ! empty($this->drift);
    }

    public function passed(): bool
    {
        return $this->outcome === self::PASSED;
    }
}
