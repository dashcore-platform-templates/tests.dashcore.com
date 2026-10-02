<?php

namespace Dashcore\Bridge\Crypto;

use Dashcore\Bridge\BridgeVersion;
use Dashcore\Bridge\Identity\IdentityResolver;
use Illuminate\Support\Str;

class BridgeHeaders
{
    /**
     * Signed Bridge-* headers for an outbound request as this app. Identity
     * comes from the resolver so a bridge:connect-enrolled app signs with its
     * stored identity, env-configured apps exactly as before.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    public static function sign(string $method, string $path, array $query = [], string $body = ''): array
    {
        $identity = app(IdentityResolver::class);
        $timestamp = now()->toIso8601ZuluString();
        $nonce = Str::random(32);

        $canonical = CanonicalRequest::build($method, $path, $query, $timestamp, $nonce, $body);

        // Version and environment ride along as telemetry only. They are
        // deliberately excluded from the canonical string so servers that
        // predate them verify signatures unchanged.
        $headers = [
            'Bridge-App' => $identity->appId(),
            'Bridge-Key' => $identity->keyId(),
            'Bridge-Time' => $timestamp,
            'Bridge-Nonce' => $nonce,
            'Bridge-Signature' => Signature::sign($canonical, $identity->privateKey()),
            'Bridge-Version' => BridgeVersion::version(),
            'Bridge-Env' => app()->environment(),
        ];

        // The release of the fleet's shared shell, when this app has it, so
        // the control plane can show who is behind on a design change the way
        // it shows who is behind on the bridge. Omitted rather than sent as
        // "unknown": an app without the package is not on an old release of
        // it, and the hub should not count it as one.
        if (($ui = BridgeVersion::ui()) !== null) {
            $headers['Bridge-Ui-Version'] = $ui;
        }

        return $headers;
    }
}
