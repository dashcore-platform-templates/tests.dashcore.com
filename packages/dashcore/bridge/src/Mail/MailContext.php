<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Mail;

/**
 * How an app says what a message is for.
 *
 * Two headers, read by `MailRecorder` and carried to `health`. The context is
 * a short label ("lead-notification", "invite"); critical means that this
 * message not arriving is an incident in itself, whoever it was addressed to.
 *
 *     Mail::html($html, fn ($message) => $message
 *         ->to($team)
 *         ->subject($subject)
 *         ->getHeaders()->addTextHeader(MailContext::CONTEXT, 'lead-notification'));
 *
 * Or, from a Mailable, return `MailContext::headers('invite')` from headers().
 */
final class MailContext
{
    public const CONTEXT = 'X-Dashcore-Mail-Context';

    public const CRITICAL = 'X-Dashcore-Mail-Critical';

    /** Pairs an attempt with its confirmation. */
    public const ATTEMPT = 'X-Dashcore-Mail-Attempt';

    /**
     * For a Mailable's headers() method.
     */
    public static function headers(string $context, bool $critical = false): \Illuminate\Mail\Mailables\Headers
    {
        return new \Illuminate\Mail\Mailables\Headers(text: array_filter([
            self::CONTEXT => $context,
            self::CRITICAL => $critical ? '1' : null,
        ]));
    }
}
