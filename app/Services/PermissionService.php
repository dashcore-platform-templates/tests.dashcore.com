<?php

namespace App\Services;

use App\Enums\AccessLevel;
use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;

class PermissionService
{
    private array $map;

    private array $defaultDeny = [
        'owner' => 'full',
        'admin' => 'full',
    ];

    public function __construct()
    {
        $this->map = config('permissions', []);
    }

    public function check(UserRole $role, string $key): ?string
    {
        $permissions = $this->map[$key] ?? $this->defaultDeny;

        if ($key === 'finance' && $role === UserRole::Admin) {
            return Setting::get('admin_can_access_finance', false) ? 'full' : null;
        }

        return $permissions[$role->value] ?? null;
    }

    public function canAccess(UserRole $role, string $key): bool
    {
        $level = $this->check($role, $key);

        return $level === 'full' || $level === 'view';
    }

    public function hasFullAccess(UserRole $role, string $key): bool
    {
        return $this->check($role, $key) === 'full';
    }

    public function hasViewAccess(UserRole $role, string $key): bool
    {
        return $this->check($role, $key) === 'view';
    }

    public function checkForUser(User $user, string $key): ?string
    {
        $user->loadMissing('permissionOverrides');

        $override = $user->permissionOverrides->firstWhere('permission_key', $key);

        if ($override !== null) {
            return $override->access_level === AccessLevel::Deny
                ? null
                : $override->access_level->value;
        }

        return $this->check($user->role, $key);
    }

    public function canAccessForUser(User $user, string $key): bool
    {
        $level = $this->checkForUser($user, $key);

        return $level === 'full' || $level === 'view';
    }

    public function hasFullAccessForUser(User $user, string $key): bool
    {
        return $this->checkForUser($user, $key) === 'full';
    }

    public function hasViewAccessForUser(User $user, string $key): bool
    {
        return $this->checkForUser($user, $key) === 'view';
    }
}
