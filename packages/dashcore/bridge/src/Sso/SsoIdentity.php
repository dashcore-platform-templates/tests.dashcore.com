<?php

namespace Dashcore\Bridge\Sso;

/**
 * Who the sign-in server says just arrived.
 *
 * `subject` is the only stable handle: a ULID the sign-in server keeps for
 * that person forever. An email address is a label people change, and
 * matching on it alone is how one person ends up in two accounts, or two
 * people in one.
 */
final class SsoIdentity
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function __construct(
        public readonly string $subject,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
        public readonly ?string $sessionId,
        public readonly array $claims,
    ) {}

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function fromClaims(array $claims): self
    {
        return new self(
            subject: (string) $claims['sub'],
            email: isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : null,
            emailVerified: (bool) ($claims['email_verified'] ?? false),
            name: isset($claims['name']) && is_string($claims['name']) ? $claims['name'] : null,
            sessionId: isset($claims['sid']) && is_string($claims['sid']) ? $claims['sid'] : null,
            claims: $claims,
        );
    }
}
