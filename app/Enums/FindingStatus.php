<?php

namespace App\Enums;

enum FindingStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Ignored = 'ignored';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
