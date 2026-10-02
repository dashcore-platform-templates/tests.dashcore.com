<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\UserRole;
use App\Livewire\Admin\PermissionManager;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PermissionManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_permissions_page(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->get(route('permissions'))
            ->assertOk();
    }

    public function test_admin_can_view_permissions_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->get(route('permissions'))
            ->assertOk();
    }

    public function test_member_gets_403_on_permissions_page(): void
    {
        $member = User::factory()->create(['role' => UserRole::Member]);

        $this->actingAs($member)
            ->get(route('permissions'))
            ->assertForbidden();
    }

    public function test_finance_gets_403_on_permissions_page(): void
    {
        $finance = User::factory()->create(['role' => UserRole::Finance]);

        $this->actingAs($finance)
            ->get(route('permissions'))
            ->assertForbidden();
    }

    public function test_selecting_user_loads_existing_overrides(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $member = User::factory()->create(['role' => UserRole::Member]);

        UserPermission::create([
            'user_id' => $member->id,
            'permission_key' => 'billing',
            'access_level' => AccessLevel::Full,
        ]);

        Livewire::actingAs($owner)
            ->test(PermissionManager::class)
            ->set('selectedUserId', $member->id)
            ->assertSet('overrides.billing', 'full');
    }

    public function test_saving_creates_user_permission_records(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $member = User::factory()->create(['role' => UserRole::Member]);

        Livewire::actingAs($owner)
            ->test(PermissionManager::class)
            ->set('selectedUserId', $member->id)
            ->set('overrides.billing', 'view')
            ->call('save')
            ->assertDispatched('overrides-saved');

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $member->id,
            'permission_key' => 'billing',
            'access_level' => 'view',
        ]);
    }

    public function test_saving_inherit_deletes_existing_override(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $member = User::factory()->create(['role' => UserRole::Member]);

        UserPermission::create([
            'user_id' => $member->id,
            'permission_key' => 'billing',
            'access_level' => AccessLevel::Full,
        ]);

        Livewire::actingAs($owner)
            ->test(PermissionManager::class)
            ->set('selectedUserId', $member->id)
            ->set('overrides.billing', 'inherit')
            ->call('save');

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $member->id,
            'permission_key' => 'billing',
        ]);
    }

    public function test_cannot_deny_self_on_permissions_key(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        Livewire::actingAs($owner)
            ->test(PermissionManager::class)
            ->set('selectedUserId', $owner->id)
            ->set('overrides.permissions', 'deny')
            ->call('save')
            ->assertDispatched('self-deny-prevented')
            ->assertSet('overrides.permissions', 'inherit');

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $owner->id,
            'permission_key' => 'permissions',
            'access_level' => 'deny',
        ]);
    }

    public function test_can_deny_other_user_on_permissions_key(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($owner)
            ->test(PermissionManager::class)
            ->set('selectedUserId', $admin->id)
            ->set('overrides.permissions', 'deny')
            ->call('save')
            ->assertDispatched('overrides-saved');

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $admin->id,
            'permission_key' => 'permissions',
            'access_level' => 'deny',
        ]);
    }

    public function test_reset_reloads_from_database(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $member = User::factory()->create(['role' => UserRole::Member]);

        UserPermission::create([
            'user_id' => $member->id,
            'permission_key' => 'billing',
            'access_level' => AccessLevel::View,
        ]);

        Livewire::actingAs($owner)
            ->test(PermissionManager::class)
            ->set('selectedUserId', $member->id)
            ->set('overrides.billing', 'deny')
            ->call('resetOverrides')
            ->assertSet('overrides.billing', 'view');
    }
}
