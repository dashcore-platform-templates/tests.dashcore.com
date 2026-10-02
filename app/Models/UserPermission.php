<?php

namespace App\Models;

use App\Enums\AccessLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'access_level' => AccessLevel::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
