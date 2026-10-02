<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_hierarchy_ranks_correctly(): void
    {
        $this->assertGreaterThan(UserRole::Admin->rank(), UserRole::Owner->rank());
        $this->assertGreaterThan(UserRole::Finance->rank(), UserRole::Admin->rank());
        $this->assertGreaterThan(UserRole::Member->rank(), UserRole::Finance->rank());
    }

    public function test_owner_is_at_least_all_roles(): void
    {
        $this->assertTrue(UserRole::Owner->isAtLeast(UserRole::Owner));
        $this->assertTrue(UserRole::Owner->isAtLeast(UserRole::Admin));
        $this->assertTrue(UserRole::Owner->isAtLeast(UserRole::Finance));
        $this->assertTrue(UserRole::Owner->isAtLeast(UserRole::Member));
    }

    public function test_admin_is_at_least_admin_finance_and_member_but_not_owner(): void
    {
        $this->assertFalse(UserRole::Admin->isAtLeast(UserRole::Owner));
        $this->assertTrue(UserRole::Admin->isAtLeast(UserRole::Admin));
        $this->assertTrue(UserRole::Admin->isAtLeast(UserRole::Finance));
        $this->assertTrue(UserRole::Admin->isAtLeast(UserRole::Member));
    }

    public function test_finance_is_at_least_finance_and_member_but_not_owner_or_admin(): void
    {
        $this->assertFalse(UserRole::Finance->isAtLeast(UserRole::Owner));
        $this->assertFalse(UserRole::Finance->isAtLeast(UserRole::Admin));
        $this->assertTrue(UserRole::Finance->isAtLeast(UserRole::Finance));
        $this->assertTrue(UserRole::Finance->isAtLeast(UserRole::Member));
    }

    public function test_member_is_at_least_only_member(): void
    {
        $this->assertFalse(UserRole::Member->isAtLeast(UserRole::Owner));
        $this->assertFalse(UserRole::Member->isAtLeast(UserRole::Admin));
        $this->assertFalse(UserRole::Member->isAtLeast(UserRole::Finance));
        $this->assertTrue(UserRole::Member->isAtLeast(UserRole::Member));
    }

    public function test_owner_passes_all_role_checks(): void
    {
        $user = User::factory()->make(['role' => UserRole::Owner]);

        $this->assertTrue($user->isOwner());
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->isFinance());
        $this->assertTrue($user->isMember());
    }

    public function test_admin_passes_admin_finance_and_member_but_not_owner(): void
    {
        $user = User::factory()->make(['role' => UserRole::Admin]);

        $this->assertFalse($user->isOwner());
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->isFinance());
        $this->assertTrue($user->isMember());
    }

    public function test_finance_passes_finance_and_member_but_not_owner_or_admin(): void
    {
        $user = User::factory()->make(['role' => UserRole::Finance]);

        $this->assertFalse($user->isOwner());
        $this->assertFalse($user->isAdmin());
        $this->assertTrue($user->isFinance());
        $this->assertTrue($user->isMember());
    }

    public function test_member_passes_only_member(): void
    {
        $user = User::factory()->make(['role' => UserRole::Member]);

        $this->assertFalse($user->isOwner());
        $this->assertFalse($user->isAdmin());
        $this->assertFalse($user->isFinance());
        $this->assertTrue($user->isMember());
    }

    public function test_owner_can_access_admin_gated_routes(): void
    {
        $user = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_admin_can_access_admin_gated_routes(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_finance_cannot_access_admin_gated_routes(): void
    {
        $user = User::factory()->create(['role' => UserRole::Finance]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_member_cannot_access_admin_gated_routes(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }
}
