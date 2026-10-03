<?php

namespace App\Scanning\Inventory;

use App\Enums\SurfaceKind;

/**
 * The app's own routes, as `route:list --json --except-vendor` reports them
 * from the checkout. One surface per method and URI, because a GET and a
 * POST on the same path are two different things that can break.
 *
 * `--except-vendor` drops every route whose action is a vendor class, and a
 * Livewire full-page route (`Route::livewire('transactions', …)`) is one:
 * its action is Livewire's page controller. Those are the app's own pages,
 * so they are read back from the full list with livewirePagesFromJson().
 */
class RouteInventory
{
    /** URIs the framework registers on the app's behalf. */
    private const IGNORE = ['up', 'storage/{path}', 'sanctum/csrf-cookie'];

    /** Actions that mean "this route renders one of the app's Livewire components". */
    private const LIVEWIRE_PAGE = '/^Livewire\\\\(Mechanisms\\\\HandleRouting\\\\LivewirePageController|Volt\\\\)/';

    /**
     * Only the Livewire full-page routes from a full `route:list --json`.
     *
     * @return list<DiscoveredSurface>
     */
    public function livewirePagesFromJson(string $json): array
    {
        return $this->fromJson($json, fn (array $route) => preg_match(self::LIVEWIRE_PAGE, (string) ($route['action'] ?? '')) === 1);
    }

    /**
     * @param  string  $json  route:list's JSON output
     * @param  (callable(array<string, mixed>): bool)|null  $keep
     * @return list<DiscoveredSurface>
     */
    public function fromJson(string $json, ?callable $keep = null): array
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

            if ($keep !== null && ! $keep($route)) {
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
