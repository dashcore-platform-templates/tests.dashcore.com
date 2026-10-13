<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One email this app tried to send. Written through the query builder by
 * `MailRecorder` (see `Recorder` for why); the model is for reading.
 */
class MailAttempt extends Model
{
    protected $table = 'bridge_mail_attempts';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'critical' => 'boolean',
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
            'reported_at' => 'datetime',
        ];
    }
}
