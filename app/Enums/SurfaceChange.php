<?php

namespace App\Enums;

/**
 * How a surface moved since the app's previous scan — the ratchet.
 */
enum SurfaceChange: string
{
    /** New since the last scan, and no test proves it. */
    case NewUntested = 'new-untested';

    /** Was tested last scan, is not now. */
    case Lost = 'lost';

    /** Was not tested last scan, is now. */
    case Gained = 'gained';

    public function label(): string
    {
        return match ($this) {
            self::NewUntested => 'New, untested',
            self::Lost => 'Lost coverage',
            self::Gained => 'Newly tested',
        };
    }

    public function isRegression(): bool
    {
        return $this !== self::Gained;
    }
}
