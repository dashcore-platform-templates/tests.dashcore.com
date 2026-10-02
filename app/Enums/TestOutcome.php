<?php

namespace App\Enums;

enum TestOutcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Errored = 'errored';
    case Skipped = 'skipped';
    case Incomplete = 'incomplete';
    case Risky = 'risky';

    /**
     * Whether reaching a surface from this test proves the surface works.
     */
    public function proves(): bool
    {
        return $this === self::Passed;
    }

    public function isFailure(): bool
    {
        return $this === self::Failed || $this === self::Errored;
    }
}
