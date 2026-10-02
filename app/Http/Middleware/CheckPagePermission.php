<?php

namespace App\Http\Middleware;

use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPagePermission
{
    public function __construct(
        private PermissionService $permissions
    ) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        $user = $request->user();

        if (! $user || ! $this->permissions->canAccessForUser($user, $key)) {
            abort(403);
        }

        return $next($request);
    }
}
