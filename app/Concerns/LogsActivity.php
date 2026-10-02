<?php

namespace App\Concerns;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function (Model $model) {
            $model->logActivity('created');
        });

        static::updated(function (Model $model) {
            $model->logActivity('updated', [
                'old' => $model->getOriginal(),
                'new' => $model->getChanges(),
            ]);
        });

        static::deleted(function (Model $model) {
            $model->logActivity('deleted');
        });
    }

    public function logActivity(string $action, ?array $properties = null): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $this->getMorphClass(),
            'subject_id' => $this->getKey(),
            'properties' => $properties,
        ]);
    }
}
