<?php

namespace Dashcore\Bridge\Events;

use Dashcore\Bridge\Sso\SsoIdentity;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Someone signed in through the fleet's sign-in server. Carries the identity
 * as well as the user, so an app can keep a role, a name or a photo in step
 * with what the sign-in server holds.
 */
class SsoSignedIn
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly SsoIdentity $identity,
    ) {}
}
