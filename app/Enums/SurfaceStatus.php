<?php

namespace App\Enums;

/**
 * What a scan found for one surface.
 *
 * Tested means a passing test with at least one assertion exercised it. Weak
 * means it was only reached by tests that prove nothing about it: failing,
 * erroring, risky, or asserting nothing. Missing means no test reached it.
 */
enum SurfaceStatus: string
{
    case Tested = 'tested';
    case Weak = 'weak';
    case Missing = 'missing';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
