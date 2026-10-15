<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Fleet;

use Dashcore\Bridge\Identity\IdentityResolver;
use Dashcore\Bridge\Keys\KeysetResolver;

/**
 * Turns a fleet app key (`api`, `health`) into the peer id it has here.
 *
 * Peer ids are hostnames, and they differ per environment, so a fleet key on
 * its own names nothing a manifest holds. Asking `Bridge::to('health')` is
 * how telemetry went missing fleet-wide, and `Bridge::to('api')` is how call
 * reports did — "No configured peer [api]" on every run. Both now come here.
 *
 * An explicit services.fleet entry still wins; a key that already is a peer
 * id is used as is; otherwise the peer in the manifest whose hostname is that
 * app's subdomain. An app looking for itself finds its own id, since a
 * manifest's peers leave the app itself out.
 */
final class PeerLocator
{
    public function __construct(
        private readonly IdentityResolver $identity,
    ) {}

    public function find(string $key): string
    {
        $configured = config("services.fleet.{$key}");

        if (filled($configured)) {
            return (string) $configured;
        }

        $peers = app(KeysetResolver::class)->peers();

        if (in_array($key, $peers, true)) {
            return $key;
        }

        $subdomain = Catalogue::has($key) ? Catalogue::app($key)['subdomain'] : null;

        if ($subdomain !== null) {
            foreach ([$this->identity->appId(), ...$peers] as $id) {
                if (is_string($id) && str_starts_with($id, $subdomain.'.')) {
                    return $id;
                }
            }
        }

        return $key;
    }
}
