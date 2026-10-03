<?php

namespace App\Enums;

enum ExplorationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
