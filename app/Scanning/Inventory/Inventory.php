<?php

namespace App\Scanning\Inventory;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Everything in a checkout that a suite owes a test, from all three sources.
 */
class Inventory
{
    /** @var list<string> */
    public array $notes = [];

    public function __construct(
        private readonly RouteInventory $routes,
        private readonly CodeInventory $code,
        private readonly ViewComponentInventory $views,
    ) {}

    /**
     * @param  array<string, string>  $environment
     * @return list<DiscoveredSurface>
     */
    public function discover(string $root, string $php, array $environment): array
    {
        $this->notes = [];

        $routes = Process::path($root)
            ->timeout(300)
            ->env($environment)
            ->run([$php, 'artisan', 'route:list', '--json', '--except-vendor']);

        $routeSurfaces = $routes->successful() ? $this->routes->fromJson($routes->output()) : [];

        if ($routes->failed()) {
            $this->notes[] = 'Routes could not be listed, so none are counted: '.Str::limit(trim($routes->errorOutput() ?: $routes->output()), 500);
        }

        $surfaces = [
            ...$routeSurfaces,
            ...$this->code->discover($root),
            ...$this->views->discover($root),
        ];

        foreach ([...$this->code->errors, ...$this->views->errors] as $error) {
            $this->notes[] = 'Could not parse '.$error;
        }

        // Two sources can describe the same thing; the first one wins.
        $unique = [];

        foreach ($surfaces as $surface) {
            $unique[$surface->key()] ??= $surface;
        }

        return array_values($unique);
    }
}
