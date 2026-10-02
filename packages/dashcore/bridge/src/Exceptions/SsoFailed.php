<?php

namespace Dashcore\Bridge\Exceptions;

/**
 * A sign-in that did not complete.
 *
 * The message is written for the person in front of the screen, because it
 * is shown to them: it says what to do next, never which check failed. The
 * detail belongs in the log, where `context` puts it.
 */
class SsoFailed extends BridgeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, public readonly array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
