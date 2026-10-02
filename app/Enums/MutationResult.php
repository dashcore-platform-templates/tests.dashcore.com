<?php

namespace App\Enums;

/**
 * What happened when a tested method's body was replaced with a trivial
 * default and the tests that reach it were run again.
 */
enum MutationResult: string
{
    /** A test that passed before failed: the tests notice the method is broken. */
    case Killed = 'killed';

    /** Every test still passed: they reach the method but do not check what it does. */
    case Survived = 'survived';

    /** The mutant could not be built or run; nothing is concluded. */
    case Error = 'error';
}
