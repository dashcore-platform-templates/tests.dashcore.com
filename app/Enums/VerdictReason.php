<?php

namespace App\Enums;

/**
 * Why a surface is Weak rather than Tested.
 */
enum VerdictReason: string
{
    /** Only failing, erroring, risky or assertion-free tests reach it. */
    case NoProvingTest = 'no-proving-test';

    /** Its tests still pass with its body gutted. */
    case MutantSurvived = 'mutant-survived';

    public function label(): string
    {
        return match ($this) {
            self::NoProvingTest => 'Only reached by tests that prove nothing',
            self::MutantSurvived => 'Tests still pass when it is broken',
        };
    }
}
