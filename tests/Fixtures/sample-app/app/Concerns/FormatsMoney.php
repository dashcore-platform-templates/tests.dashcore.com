<?php

namespace App\Concerns;

trait FormatsMoney
{
    public function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}
