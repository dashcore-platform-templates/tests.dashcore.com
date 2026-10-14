<?php

namespace App\Enums;

/**
 * How often a workflow walks on its own. Manual ones only run when someone
 * presses "Run now".
 */
enum WorkflowFrequency: string
{
    case Manual = 'manual';
    case Every15Minutes = '15m';
    case Hourly = '1h';
    case Every6Hours = '6h';
    case Daily = '1d';

    public function minutes(): ?int
    {
        return match ($this) {
            self::Manual => null,
            self::Every15Minutes => 15,
            self::Hourly => 60,
            self::Every6Hours => 360,
            self::Daily => 1440,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Only when run by hand',
            self::Every15Minutes => 'Every 15 minutes',
            self::Hourly => 'Every hour',
            self::Every6Hours => 'Every 6 hours',
            self::Daily => 'Once a day',
        };
    }
}
