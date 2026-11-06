<?php

namespace App\Simulation;

/**
 * Which contact form a stand-in website serves, so a journey walks the form
 * that is actually there.
 *
 * dashcore.com's own site takes enquiries at /lets-talk (#df-* fields). The
 * client website template, which a practice or client fleet's website runs,
 * serves resources/site/contact.html at /contact (#contact-form, posting to
 * /submit-contact). The website's checkout says which: a contact.html holding
 * #contact-form is the client template. A `contact_form` key on the fleet's
 * website entry ('contact' or 'lets-talk') overrides the read.
 */
final class ContactForm
{
    public const CONTACT = 'contact';

    public const LETS_TALK = 'lets-talk';

    /** @param  array<string, mixed>|null  $website  the fleet's website entry */
    public static function of(?array $website): string
    {
        $chosen = $website['contact_form'] ?? null;

        if (in_array($chosen, [self::CONTACT, self::LETS_TALK], true)) {
            return $chosen;
        }

        $checkout = $website['checkout'] ?? null;

        if (! is_string($checkout) || $checkout === '') {
            return self::LETS_TALK;
        }

        $page = rtrim($checkout, '/').'/resources/site/contact.html';

        if (! is_file($page) || ! is_readable($page)) {
            return self::LETS_TALK;
        }

        return preg_match('/id\s*=\s*["\']contact-form["\']/', (string) file_get_contents($page)) === 1
            ? self::CONTACT
            : self::LETS_TALK;
    }
}
