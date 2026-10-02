<?php

namespace Dashcore\Bridge\Contracts;

use Dashcore\Bridge\Sso\SsoIdentity;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Turns "who the sign-in server says this is" into "who that is here".
 *
 * An app with its own rules — roles, an employee record, an invitation that
 * has to exist first — binds its own implementation in a service provider.
 * Returning null refuses the sign-in.
 */
interface ResolvesSsoUser
{
    public function resolve(SsoIdentity $identity): ?Authenticatable;
}
