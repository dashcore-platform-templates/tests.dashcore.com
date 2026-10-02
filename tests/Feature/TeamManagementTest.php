<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Team\Index;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_access_team_page(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->get(route('team'))
            ->assertOk();
    }

    public function test_admin_can_access_team_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->get(route('team'))
            ->assertOk();
    }

    public function test_finance_cannot_access_team_page(): void
    {
        $finance = User::factory()->create(['role' => UserRole::Finance]);

        $this->actingAs($finance)
            ->get(route('team'))
            ->assertForbidden();
    }

    public function test_member_cannot_access_team_page(): void
    {
        $member = User::factory()->create(['role' => UserRole::Member]);

        $this->actingAs($member)
            ->get(route('team'))
            ->assertForbidden();
    }

    public function test_team_page_lists_all_users(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $member = User::factory()->create(['role' => UserRole::Member, 'name' => 'Test Member']);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->assertSee($owner->name)
            ->assertSee($member->name);
    }

    public function test_can_change_user_role(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $member = User::factory()->create(['role' => UserRole::Member]);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->call('changeRole', $member->id, 'admin')
            ->assertDispatched('role-changed');

        $this->assertEquals(UserRole::Admin, $member->fresh()->role);
    }

    public function test_cannot_change_own_role(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->call('changeRole', $owner->id, 'member');

        $this->assertEquals(UserRole::Owner, $owner->fresh()->role);
    }

    public function test_can_remove_user(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $member = User::factory()->create(['role' => UserRole::Member]);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->call('removeUser', $member->id)
            ->assertDispatched('user-removed');

        $this->assertNull(User::find($member->id));
    }

    public function test_cannot_remove_self(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->call('removeUser', $owner->id);

        $this->assertNotNull(User::find($owner->id));
    }

    public function test_can_toggle_admin_finance_access(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->assertSet('adminCanAccessFinance', false)
            ->call('toggleAdminFinanceAccess')
            ->assertSet('adminCanAccessFinance', true)
            ->assertDispatched('setting-saved');

        $this->assertTrue(Setting::get('admin_can_access_finance'));
    }

    public function test_admin_finance_toggle_persists(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        Setting::set('admin_can_access_finance', true);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->assertSet('adminCanAccessFinance', true);
    }

    public function test_invalid_role_is_rejected(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $member = User::factory()->create(['role' => UserRole::Member]);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->call('changeRole', $member->id, 'invalid-role');

        $this->assertEquals(UserRole::Member, $member->fresh()->role);
    }
}
