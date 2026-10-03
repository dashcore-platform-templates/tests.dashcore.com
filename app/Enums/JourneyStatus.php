<?php

namespace App\Enums;

/**
 * Where a drafted journey stands: on trial in the real browser, proven and
 * in the rotation, held back after failing its trial, or retired.
 */
enum JourneyStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Held = 'held';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'On trial',
            self::Active => 'Active',
            self::Held => 'Held for review',
            self::Retired => 'Retired',
        };
    }

    public function runs(): bool
    {
        return in_array($this, [self::Trial, self::Active], true);
    }
}
