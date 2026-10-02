<?php

namespace App\Enums;

enum AccessLevel: string
{
    case Full = 'full';
    case View = 'view';
    case Deny = 'deny';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full',
            self::View => 'View',
            self::Deny => 'Deny',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Full => 'bg-success-500/15 text-success-500 ring-success-500/30',
            self::View => 'bg-accent-500/15 text-accent-400 ring-accent-500/30',
            self::Deny => 'bg-red-500/15 text-red-400 ring-red-500/30',
        };
    }
}
