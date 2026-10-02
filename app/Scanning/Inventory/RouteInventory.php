<?php

namespace App\Scanning\Inventory;

use App\Enums\SurfaceKind;

/**
 * The app's own routes, as `route:list --json --except-vendor` reports them
 * from the checkout. One surface per method and URI, because a GET and a
 * POST on the same path are two different things that can break.
 */
class RouteInventory
{
    /** URIs the framework registers on the app's behalf. */
    private const IGNORE = ['up', 'storage/{path}', 'sanctum/csrf-cookie'];

    /**
     * @param  string  $json  route:list's JSON output
     * @return list<DiscoveredSurface>
     */
    public function fromJson(string $json): array
    {
        $start = strpos($json, '[');
        $routes = $start === false ? null : json_decode(substr($json, $start), true);

        if (! is_array($routes)) {
            return [];
        }

        $surfaces = [];

        foreach ($routes as $route) {
            $uri = (string) ($route['uri'] ?? '');

            if ($uri === '' || in_array($uri, self::IGNORE, true) || str_starts_with($uri, 'livewire') || str_starts_with($uri, '_')) {
                continue;
            }

            foreach (explode('|', (string) ($route['method'] ?? '')) as $method) {
                if ($method === '' || $method === 'HEAD') {
                    continue;
                }

                $identifier = $method.' '.$uri;
                $middleware = array_values(array_filter((array) ($route['middleware'] ?? []), 'is_string'));

                $surfaces[] = new DiscoveredSurface(
                    kind: SurfaceKind::Route,
                    identifier: $identifier,
                    label: $method.' '.($uri === '/' ? '/' : '/'.$uri),
                    match: [$identifier],
                    meta: array_filter([
                        'name' => $route['name'] ?? null,
                        'action' => $route['action'] ?? null,
                        'middleware' => $middleware,
                        'domain' => $route['domain'] ?? null,
                    ]),
                );
            }
        }

        return $surfaces;
    }
}
