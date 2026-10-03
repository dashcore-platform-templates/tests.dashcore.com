<?php

namespace App\Services;

use App\Concerns\FormatsMoney;

class Pricing
{
    use FormatsMoney;

    public function quote(int $cents): string
    {
        return $this->money($cents);
    }
}
