<?php

namespace Dashcore\Bridge\Exceptions;

/**
 * The peer answered 403 without the bridge's own denial.
 *
 * A missing grant is answered by EnsureBridgeScope with a body naming
 * BRIDGE_SCOPE_DENIED. A bare 403 came from something else: an edge firewall
 * or challenge in front of the app, or the app's own authorisation. Reported
 * as a missing grant, it sent an owner to approve a grant the hub already
 * held while the peer was refusing every request (13 October 2026). A
 * PeerError, because the fault is on the peer's side of the call.
 */
class PeerForbidden extends PeerError {}
