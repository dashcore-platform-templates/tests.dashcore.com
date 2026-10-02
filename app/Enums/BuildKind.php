<?php

namespace App\Enums;

enum BuildKind: string
{
    /** An agent writes tests for open surfaces. */
    case Tests = 'tests';

    /** The fleet baseline (dashcore/testkit) is installed or upgraded. */
    case Testkit = 'testkit';

    public function label(): string
    {
        return match ($this) {
            self::Tests => 'Tests',
            self::Testkit => 'Fleet baseline',
        };
    }
}
