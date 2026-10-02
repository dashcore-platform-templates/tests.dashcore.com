<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Collection;

class NavigationRegistry
{
    protected array $items = [];

    public function register(
        string $group,
        string $label,
        string $route,
        ?string $icon = null,
        int $order = 0,
        ?string $permissionKey = null
    ): void {
        $this->items[] = [
            'group' => $group,
            'label' => $label,
            'route' => $route,
            'icon' => $icon,
            'order' => $order,
            'permissionKey' => $permissionKey,
        ];
    }

    public function items(): Collection
    {
        return collect($this->items)
            ->sortBy('order')
            ->groupBy('group');
    }

    public function itemsFor(UserRole $role): Collection
    {
        $permissions = app(PermissionService::class);

        return collect($this->items)
            ->filter(function (array $item) use ($permissions, $role) {
                $key = $item['permissionKey'] ?? '__default_deny__';

                return $permissions->canAccess($role, $key);
            })
            ->sortBy('order')
            ->groupBy('group');
    }

    public function itemsForUser(User $user): Collection
    {
        $permissions = app(PermissionService::class);

        return collect($this->items)
            ->filter(function (array $item) use ($permissions, $user) {
                $key = $item['permissionKey'] ?? '__default_deny__';

                return $permissions->canAccessForUser($user, $key);
            })
            ->sortBy('order')
            ->groupBy('group');
    }

    public function hasItems(): bool
    {
        return count($this->items) > 0;
    }

    public function hasItemsFor(UserRole $role): bool
    {
        $permissions = app(PermissionService::class);

        foreach ($this->items as $item) {
            $key = $item['permissionKey'] ?? '__default_deny__';
            if ($permissions->canAccess($role, $key)) {
                return true;
            }
        }

        return false;
    }

    public function hasItemsForUser(User $user): bool
    {
        $permissions = app(PermissionService::class);

        foreach ($this->items as $item) {
            $key = $item['permissionKey'] ?? '__default_deny__';
            if ($permissions->canAccessForUser($user, $key)) {
                return true;
            }
        }

        return false;
    }
}
