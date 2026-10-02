<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Models\UserPermission;
use App\Services\PermissionService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class PermissionManager extends Component
{
    public ?int $selectedUserId = null;

    /** @var array<string, string> permission_key => 'inherit'|'full'|'view'|'deny' */
    public array $overrides = [];

    public function updatedSelectedUserId(): void
    {
        $this->loadOverrides();
    }

    public function resetOverrides(): void
    {
        $this->loadOverrides();
        $this->dispatch('overrides-reset');
    }

    public function save(): void
    {
        $user = $this->selectedUser();

        if (! $user) {
            return;
        }

        if ($user->id === auth()->id() && ($this->overrides['permissions'] ?? null) === 'deny') {
            $this->overrides['permissions'] = 'inherit';
            $this->dispatch('self-deny-prevented');

            return;
        }

        DB::transaction(function () use ($user) {
            $user->permissionOverrides()->delete();

            foreach ($this->overrides as $key => $value) {
                if ($value === 'inherit') {
                    continue;
                }

                UserPermission::create([
                    'user_id' => $user->id,
                    'permission_key' => $key,
                    'access_level' => $value,
                ]);
            }
        });

        $this->dispatch('overrides-saved');
    }

    public function effectiveLevel(string $key): ?string
    {
        $user = $this->selectedUser();

        if (! $user) {
            return null;
        }

        $override = $this->overrides[$key] ?? 'inherit';

        if ($override === 'inherit') {
            return app(PermissionService::class)->check($user->role, $key);
        }

        return $override === 'deny' ? null : $override;
    }

    public function roleDefault(string $key): ?string
    {
        $user = $this->selectedUser();

        return $user ? app(PermissionService::class)->check($user->role, $key) : null;
    }

    private function selectedUser(): ?User
    {
        return $this->selectedUserId ? User::find($this->selectedUserId) : null;
    }

    private function loadOverrides(): void
    {
        $this->overrides = [];

        $user = $this->selectedUser();

        if (! $user) {
            return;
        }

        $existing = $user->permissionOverrides->keyBy('permission_key');

        foreach ($this->permissionKeys() as $key) {
            $this->overrides[$key] = isset($existing[$key])
                ? $existing[$key]->access_level->value
                : 'inherit';
        }
    }

    private function permissionKeys(): array
    {
        $keys = array_keys(config('permissions', []));

        return array_values(array_diff($keys, ['modules']));
    }

    public function render()
    {
        return view('livewire.admin.permission-manager', [
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role']),
            'modules' => config('permissions.modules', []),
            'selectedUser' => $this->selectedUser(),
        ]);
    }
}
