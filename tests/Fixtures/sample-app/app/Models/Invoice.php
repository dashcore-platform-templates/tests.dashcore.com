<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function total(): int
    {
        return $this->lines->sum('amount');
    }
}
