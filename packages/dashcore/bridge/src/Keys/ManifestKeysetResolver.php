<?php

namespace Dashcore\Bridge\Keys;

use Dashcore\Bridge\Crypto\BridgeHeaders;
use Dashcore\Bridge\Crypto\Signature;
use Dashcore\Bridge\Exceptions\PeerUnreachable;
use Dashcore\Bridge\Identity\IdentityResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use function Illuminate\Support\defer;

class ManifestKeysetResolver implements KeysetResolver
{
    protected const CACHE_KEY = 'bridge:manifest';

    protected const LAST_KNOWN_GOOD_KEY = 'bridge:manifest:lkg';

    public function publicKeysFor(string $appId): array
    {
        return $this->manifest()['apps'][$appId]['keys'] ?? [];
    }

    public function urlFor(string $appId): ?string
    {
        return $this->manifest()['apps'][$appId]['url'] ?? null;
    }

    public function grantsFor(string $appId): array
    {
        return $this->manifest()['grants'][app(IdentityResolver::class)->appId()][$appId] ?? [];
    }

    public function peers(): array
    {
        return array_values(array_diff(array_keys($this->manifest()['apps'] ?? []), [app(IdentityResolver::class)->appId()]));
    }

    /**
     * The slugs of this install's local tools: Dashcore's own tools on the
     * owner's Mac, which enrol so their calls verify but are never deployed
     * and take no inbound call. They are in `apps` because every target must
     * know their keys; `install.local_tools` is how a consumer tells them
     * apart from a peer it can reach. A hub too old to publish it has none.
     *
     * @return list<string>
     */
    public function localTools(): array
    {
        return array_values(array_filter((array) ($this->manifest()['install']['local_tools'] ?? []), 'is_string'));
    }

    /** Held while one process of this app refetches, so a burst asks once. */
    protected const REFRESH_LOCK = 'bridge:manifest:refreshing';

    /** A forced refetch (unknown key id) at most this often. */
    protected const FORCED_REFRESH_KEY = 'bridge:manifest:forced';

    /**
     * The verified fleet manifest.
     *
     * Read through Cache::memo(), so a request or job reads the store once
     * however many lookups it makes. Every peer address, key and grant check
     * reads this document: on 2 October one cockpit page render read it 132
     * times from a database-backed cache, unserialising the whole manifest
     * each time, and its page updates averaged 3.7 seconds.
     *
     * Fresh from cache when it is. When it is not, and the last good copy is
     * young enough to trust (`bridge.manifest_max_stale`), that copy is
     * returned at once and the refetch happens after the response, so no
     * request or job waits on the control plane. Before this, an expired
     * cache meant the next caller fetched inline and waited up to the bridge
     * timeout: on 2 October roughly forty calls a day across the fleet sat
     * ten seconds on a slow manifest, each one reported as an error, while
     * the copy they fell back to was minutes old.
     *
     * With no copy at all, or one too old to trust, it fetches inline as it
     * always did, and a control plane that does not answer still falls back
     * to the last good copy rather than breaking traffic.
     *
     * @return array{apps: array<string, array{url: string, keys: array<string, string>}>, grants: array<string, array<string, list<string>>>}
     */
    public function manifest(): array
    {
        $fresh = Cache::memo()->get(static::CACHE_KEY);

        if ($fresh !== null) {
            return $fresh;
        }

        $lastKnownGood = Cache::memo()->get(static::LAST_KNOWN_GOOD_KEY);

        if (is_array($lastKnownGood) && $this->youngEnough($lastKnownGood)) {
            $this->refreshAfterResponse();

            return $lastKnownGood;
        }

        try {
            return $this->fetchAndStore();
        } catch (\Throwable $e) {
            if ($lastKnownGood === null) {
                throw $e;
            }

            report($e);

            return $lastKnownGood;
        }
    }

    /**
     * Refetch now, because something this copy does not know about has
     * turned up — a key id it has never seen, after a rotation — so the
     * rotation propagates within one request.
     *
     * At most once a minute per app: the key id comes from the caller, and
     * an unthrottled refetch on every unknown one let anybody who could
     * reach an app make it ask the control plane once per request.
     */
    public function refresh(): void
    {
        if (! Cache::add(static::FORCED_REFRESH_KEY, true, 60)) {
            return;
        }

        try {
            $this->fetchAndStore();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @param  array<string, mixed>  $manifest */
    protected function youngEnough(array $manifest): bool
    {
        $issued = $manifest['issued_at'] ?? null;

        if (! is_string($issued) || ($at = strtotime($issued)) === false) {
            return false;
        }

        return time() - $at <= (int) config('bridge.manifest_max_stale', 3600);
    }

    /**
     * One refetch after the response, the command or the queued job — named,
     * so however many lookups miss in one unit of work it is queued once —
     * and one per app at a time across processes. defer() rather than a
     * terminating callback because a queue worker never terminates between
     * jobs, and a worker would have served the old copy until it restarted.
     *
     * A failure is logged, not reported: the copy in hand is still young
     * enough to serve, and the inline path reports in earnest once it is not.
     */
    protected function refreshAfterResponse(): void
    {
        defer(function () {
            $lock = Cache::lock(static::REFRESH_LOCK, (int) config('bridge.timeout', 10) + 5);

            if (! $lock->get()) {
                return;
            }

            try {
                $this->fetchAndStore();
            } catch (\Throwable $e) {
                Log::warning('bridge.manifest.refresh_failed', ['error' => $e->getMessage()]);
            } finally {
                $lock->release();
            }
        }, name: 'bridge:manifest:refresh', always: true);
    }

    /**
     * Fetch, verify and store the manifest now, throwing when the control
     * plane does not answer — for a check that must report what it found
     * (bridge:doctor) rather than quietly serve the last good copy.
     *
     * @return array<string, mixed>
     */
    public function fetchNow(): array
    {
        return $this->fetchAndStore();
    }

    /** @return array<string, mixed> */
    protected function fetchAndStore(): array
    {
        $manifest = $this->fetch();

        Cache::memo()->put(static::CACHE_KEY, $manifest, config('bridge.manifest_ttl'));
        Cache::memo()->forever(static::LAST_KNOWN_GOOD_KEY, $manifest);

        return $manifest;
    }

    protected function fetch(): array
    {
        $path = '/api/bridge/v1/manifest';
        $url = rtrim(config('bridge.control.url'), '/').$path;

        $response = Http::withHeaders(BridgeHeaders::sign('GET', $path))
            ->timeout(config('bridge.timeout'))
            ->get($url);

        if ($response->failed()) {
            throw new PeerUnreachable("Control plane returned {$response->status()} for the fleet manifest.");
        }

        // The control plane wraps every response in the platform envelope;
        // the manifest itself is the `data` member.
        $payload = base64_decode($response->json('data.payload', ''), strict: true);
        $signature = $response->json('data.signature', '');

        if ($payload === false || ! Signature::verify($payload, $signature, (string) app(IdentityResolver::class)->controlPublicKey())) {
            throw new PeerUnreachable('Fleet manifest signature verification failed — refusing unsigned fleet state.');
        }

        return json_decode($payload, associative: true);
    }
}
