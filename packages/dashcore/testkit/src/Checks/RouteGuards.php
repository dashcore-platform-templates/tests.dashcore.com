<?php

namespace Dashcore\Testkit\Checks;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionFunction;

/**
 * Every route the app owns carries an authentication guard, or is declared
 * public. A new route nobody thought about is the failure this exists for.
 */
class RouteGuards
{
    public function __construct(private readonly Router $router) {}

    /**
     * Routes that are neither guarded nor declared public, as "METHOD uri".
     *
     * @return list<string>
     */
    public function unguarded(): array
    {
        $guards = (array) config('testkit.routes.guards', []);
        $public = (array) config('testkit.routes.public', []);
        $found = [];

        foreach ($this->router->getRoutes() as $route) {
            if (! $this->ownedByApp($route)) {
                continue;
            }

            // Both forms: aliases as written (bridge.auth, auth:sanctum) and the
            // classes they resolve to.
            $middleware = [...$route->gatherMiddleware(), ...$this->router->gatherRouteMiddleware($route)];

            if ($this->guarded($middleware, $guards)) {
                continue;
            }

            foreach ($this->keys($route) as $key) {
                if (! Str::is($public, $key)) {
                    $found[] = $key;
                }
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /**
     * @return list<string>
     */
    public function keys(Route $route): array
    {
        return array_values(array_map(
            fn (string $method) => $method.' '.$route->uri(),
            array_filter($route->methods(), fn (string $m) => $m !== 'HEAD'),
        ));
    }

    /**
     * Routes defined by the app, including Volt and Livewire full-page routes
     * — those are closures inside the Livewire packages, but registered from
     * the app's route files, inside its route groups.
     */
    private function ownedByApp(Route $route): bool
    {
        $uses = $route->getAction('uses');
        $vendor = base_path('vendor').DIRECTORY_SEPARATOR;

        if ($uses instanceof Closure) {
            $file = (string) (new ReflectionFunction($uses))->getFileName();

            if (! str_starts_with($file, $vendor)) {
                return true;
            }

            return str_contains($file, DIRECTORY_SEPARATOR.'livewire'.DIRECTORY_SEPARATOR)
                && array_key_exists('prefix', $route->getAction());
        }

        if (! is_string($uses) || str_contains($uses, 'SerializableClosure')) {
            return true;
        }

        $class = ltrim((string) $route->getControllerClass(), '\\');

        // A redirect exposes nothing; where it leads is checked on its own.
        if ($class === 'Illuminate\\Routing\\RedirectController') {
            return false;
        }

        // Route::view and Route::redirect use the framework's own controllers
        // but are pages the app declared.
        if ($class === '' || str_starts_with($class, 'Illuminate\\Routing\\')) {
            return true;
        }

        if (! class_exists($class)) {
            return true;
        }

        return ! str_starts_with((string) (new ReflectionClass($class))->getFileName(), $vendor);
    }

    /**
     * @param  list<string>  $middleware
     * @param  list<string>  $guards
     */
    private function guarded(array $middleware, array $guards): bool
    {
        foreach ($middleware as $name) {
            if (is_string($name) && Str::is($guards, $name)) {
                return true;
            }
        }

        return false;
    }
}
