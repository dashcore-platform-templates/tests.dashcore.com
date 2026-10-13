<?php

namespace Dashcore\Bridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The security headers every fleet app should send, from one place
 * (config `bridge.security_headers`). Added to the global middleware stack
 * by the service provider, so it covers every route an app has, its error
 * pages included.
 *
 * It never overrides a header the app set itself: an app with a stricter
 * policy of its own keeps it. Strict-Transport-Security goes out on HTTPS
 * only — over plain HTTP a browser ignores it, and a local `.test` site
 * must not be pinned. The cross-origin pair applies to HTML documents only:
 * CORP on an image or a PDF the app serves would stop another site — a mail
 * client, a partner page — from loading it, which is not what it is for.
 */
final class SetSecurityHeaders
{
    /** @var array<string, string> config key => header */
    private const HEADERS = [
        'content_security_policy' => 'Content-Security-Policy',
        'referrer_policy' => 'Referrer-Policy',
        'permissions_policy' => 'Permissions-Policy',
        'x_dns_prefetch_control' => 'X-DNS-Prefetch-Control',
    ];

    /** @var array<string, string> sent on HTML documents only */
    private const DOCUMENT_HEADERS = [
        'cross_origin_opener_policy' => 'Cross-Origin-Opener-Policy',
        'cross_origin_resource_policy' => 'Cross-Origin-Resource-Policy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $config = (array) config('bridge.security_headers', []);

        if (! ($config['enabled'] ?? true)) {
            return $response;
        }

        foreach (self::HEADERS as $key => $header) {
            $this->set($response, $header, $config[$key] ?? null);
        }

        if ($this->isDocument($response)) {
            foreach (self::DOCUMENT_HEADERS as $key => $header) {
                $this->set($response, $header, $config[$key] ?? null);
            }
        }

        if ($request->isSecure()) {
            $this->set($response, 'Strict-Transport-Security', $config['strict_transport_security'] ?? null);
        }

        return $response;
    }

    private function set(Response $response, string $header, mixed $value): void
    {
        if (! is_string($value) || trim($value) === '' || $response->headers->has($header)) {
            return;
        }

        $response->headers->set($header, $value);
    }

    private function isDocument(Response $response): bool
    {
        $type = (string) $response->headers->get('Content-Type', '');

        // Laravel sets the type when the response is prepared, after this
        // middleware has run; an HTML view arrives here with none set yet.
        return $type === '' || str_starts_with(strtolower($type), 'text/html');
    }
}
