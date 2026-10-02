<?php

namespace Dashcore\Bridge\Exceptions;

/**
 * Sign-in cannot be attempted at all: this app has no identity, the sign-in
 * server is not in the manifest, or what it published cannot be trusted.
 *
 * Separate from SsoFailed because the audience is different. This is for
 * whoever provisioned the install; SsoFailed is for the person at the screen.
 */
class SsoUnavailable extends BridgeException {}
