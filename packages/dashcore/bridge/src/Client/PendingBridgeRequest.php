<?php

namespace Dashcore\Bridge\Client;

use Dashcore\Bridge\Crypto\BridgeHeaders;
use Dashcore\Bridge\Exceptions\PeerDeniedScope;
use Dashcore\Bridge\Exceptions\PeerError;
use Dashcore\Bridge\Exceptions\PeerForbidden;
use Dashcore\Bridge\Exceptions\PeerRejectedSignature;
use Dashcore\Bridge\Exceptions\PeerUnreachable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PendingBridgeRequest
{
    protected const PREFIX = '/api/bridge/v1';

    protected ?int $timeout = null;

    protected ?int $retryTimes = null;

    protected int $retryBackoff = 100;

    protected ?int $connectRetries = null;

    public function __construct(
        protected string $appId,
        protected string $baseUrl,
    ) {}

    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    public function retry(int $times, int $backoff = 100): static
    {
        $this->retryTimes = $times;
        $this->retryBackoff = $backoff;

        return $this;
    }

    /**
     * How many more times a call that never reached its peer is tried, for
     * this call only — bridge.connect_retries otherwise. A caller that runs
     * its own retry around the call passes 0, or the two multiply.
     */
    public function connectRetries(int $times): static
    {
        $this->connectRetries = $times;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): Response
    {
        return $this->send('GET', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function post(string $path, array $data = []): Response
    {
        return $this->send('POST', $path, [], $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(string $path, array $data = []): Response
    {
        return $this->send('PUT', $path, [], $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function patch(string $path, array $data = []): Response
    {
        return $this->send('PATCH', $path, [], $data);
    }

    public function delete(string $path): Response
    {
        return $this->send('DELETE', $path);
    }

    /**
     * POST a file as multipart/form-data, the way a browser sends an upload.
     *
     * PHP never exposes a multipart body to the receiving app as raw bytes,
     * so the signature covers an empty body, as the receiver computes it.
     * What binds the file to the request is whatever the caller signs in the
     * query — the probe signs the file's size and SHA-256 — so a receiver of
     * an upload checks the file against those before it trusts it.
     *
     * Tried once: a peer that received part of an upload may have acted on
     * it, and a caller testing an upload path wants to see the failure.
     *
     * Pass an open stream rather than a string for a large file: it is read
     * from disk as it is sent instead of held in memory, twice.
     *
     * @param  string|resource  $contents
     * @param  array<string, mixed>  $query
     */
    public function upload(string $path, mixed $contents, string $filename, array $query = [], string $field = 'file'): Response
    {
        $path = static::PREFIX.'/'.ltrim($path, '/');
        $url = rtrim($this->baseUrl, '/').$path;

        if ($query !== []) {
            ksort($query);
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        try {
            $response = Http::withHeaders([
                ...BridgeHeaders::sign('POST', $path, $query, ''),
                'Accept' => 'application/json',
            ])
                ->timeout($this->timeout ?? config('bridge.timeout'))
                ->attach($field, $contents, $filename)
                ->post($url);
        } catch (ConnectionException $e) {
            throw new PeerUnreachable("Peer [{$this->appId}] is unreachable: {$e->getMessage()}", previous: $e);
        }

        return $this->checked($response, 'POST', $path);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $data
     */
    protected function send(string $method, string $path, array $query = [], ?array $data = null): Response
    {
        $path = static::PREFIX.'/'.ltrim($path, '/');
        $body = $data === null ? '' : json_encode($data);
        $retries = max(0, $this->connectRetries ?? (int) config('bridge.connect_retries', 1));

        for ($attempt = 0; ; $attempt++) {
            // Signed afresh on every attempt: the peer refuses a nonce it has
            // seen, and a retried request carrying the first one's headers
            // would come back as a signature rejection instead of an answer.
            $pending = Http::withHeaders([
                ...BridgeHeaders::sign($method, $path, $query, $body),
                'Accept' => 'application/json',
            ])->timeout($this->timeout ?? config('bridge.timeout'));

            if ($this->retryTimes !== null) {
                $pending = $pending->retry($this->retryTimes, $this->retryBackoff, throw: false);
            }

            if ($body !== '') {
                $pending = $pending->withBody($body, 'application/json');
            }

            try {
                $response = $pending->send($method, rtrim($this->baseUrl, '/').$path, ['query' => $query]);

                break;
            } catch (ConnectionException $e) {
                if ($attempt < $retries && static::neverReached($e)) {
                    usleep(500_000);

                    continue;
                }

                throw new PeerUnreachable("Peer [{$this->appId}] is unreachable: {$e->getMessage()}", previous: $e);
            }
        }

        return $this->checked($response, $method, $path);
    }

    /**
     * The response, or the exception that names what went wrong with it.
     */
    protected function checked(Response $response, string $method, string $path): Response
    {
        return match (true) {
            $response->status() === 401 => throw new PeerRejectedSignature(
                "Peer [{$this->appId}] rejected this app's signature: ".$response->json('error', 'unknown').' — run bridge:doctor.'
            ),
            $response->status() === 403 && $response->json('error') === 'BRIDGE_SCOPE_DENIED' => throw new PeerDeniedScope(
                "Peer [{$this->appId}] denied the call: ".$response->json('detail', 'missing ability grant')
            ),
            $response->status() === 403 => throw new PeerForbidden(
                "Peer [{$this->appId}] refused [{$method} {$path}] with 403 before its grant check — an edge firewall or the app itself, not a missing grant."
            ),
            $response->serverError() => throw new PeerError(
                "Peer [{$this->appId}] returned {$response->status()} for [{$method} {$path}]."
            ),
            default => $response,
        };
    }

    /**
     * Whether a failed call never got as far as the peer: no connection, no
     * name, or a connection that timed out while being made. A timeout after
     * the request was sent ("Operation timed out … bytes received") is not
     * one of these — the peer may have acted on it — so it is never retried.
     */
    protected static function neverReached(ConnectionException $e): bool
    {
        return preg_match('/Could not resolve host|Failed to connect|Connection refused|Connection timed out after|Couldn\'t connect/i', $e->getMessage()) === 1;
    }
}
