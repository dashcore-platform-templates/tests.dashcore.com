<?php

namespace App\Enums;

/**
 * Where a Proving Ground run, or one of its steps, stands.
 */
enum SimulationStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Passed = 'passed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Stopped = 'stopped';

    public function isFinished(): bool
    {
        return ! in_array($this, [self::Pending, self::Running], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
