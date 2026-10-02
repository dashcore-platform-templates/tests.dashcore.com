<?php

namespace App\Enums;

/**
 * What a scan found for one surface.
 *
 * Tested means a passing test with at least one assertion exercised it. Weak
 * means tests reach it but prove nothing about it: they fail, error, are
 * risky, assert nothing — or they keep passing when its body is gutted (a
 * surviving mutant). Missing means no test reached it.
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
