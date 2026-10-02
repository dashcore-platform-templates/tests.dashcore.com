<?php

namespace App\Enums;

enum ScanStatus: string
{
    case Queued = 'queued';
    case Preparing = 'preparing';
    case Running = 'running';
    case Analysing = 'analysing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Preparing, self::Running, self::Analysing], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
