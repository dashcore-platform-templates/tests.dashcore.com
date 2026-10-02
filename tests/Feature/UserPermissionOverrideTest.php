<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\PermissionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UserPermissionOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'permission:billing'])->get('/test-billing', fn () => 'ok');
        Route::middleware(['web', 'auth', 'permission:finance'])->get('/test-finance', fn () => 'ok');
    }

    public function test_override_full_grants_access_to_member(): void
    {
        $member = User::factory()->create(['role' => UserRole::Member]);

        UserPermission::create([
            'user_id' => $member->id,
            'permission_key' => 'billing',
            'access_level' => AccessLevel::Full,
        ]);

        $service = app(PermissionService::class);

        $this->assertTrue($service->canAccessForUser($member->fresh(), 'billing'));
        $this->assertEquals('full', $service->checkForUser($member->fresh(), 'billing'));
        $this->assertTrue($service->hasFullAccessForUser($member->fresh(), 'billing'));
    }

    public function test_override_view_grants_read_only_to_member(): void
    {
        $member = User::factory()->create(['role' => UserRole::Member]);

        UserPermission::create([
            'user_id' => $member->id,
            'permission_key' => 'billing',
            'access_level' => AccessLevel::View,
        ]);

        $service = app(PermissionService::class);

        $this->assertTrue($service->canAccessForUser($member->fresh(), 'billing'));
        $this->assertTrue($service->hasViewAccessForUser($member->fresh(), 'billing'));
        $this->assertFalse($service->hasFullAccessForUser($member->fresh(), 'billing'));
    }

    public function test_override_deny_blocks_owner_access(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        UserPermission::create([
            'user_id' => $owner->id,
            'permission_key' => 'billing',
            'access_level' => AccessLevel::Deny,
        ]);

        $service = app(PermissionService::class);

        $this->assertFalse($service->canAccessForUser($owner->fresh(), 'billing'));
        $this->assertNull($service->checkForUser($owner->fresh(), 'billing'));
    }

    public function test_no_override_falls_back_to_role_default(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $service = app(PermissionService::class);

        $this->assertEquals('view', $service->checkForUser($admin->fresh(), 'billing'));
    }

    public function test_admin_deny_override_blocks_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        UserPermission::create([
            'user_id' => $admin->id,
            'permission_key' => 'billing',
            'access_level' => AccessLevel::Deny,
        ]);

        $service = app(PermissionService::class);

        $this->assertFalse($service->canAccessForUser($admin->fresh(), 'billing'));
    }

    public function test_middleware_respects_override_grant(): void
    {
        $member = User::factory()->create(['role' => UserRole::Member]);

        UserPermission::create([
            'user_id' => $member->id,
            'permission_key' => 'billing',
            'access_level' => AccessLevel::Full,
        ]);

        $this->actingAs($member)
            ->get('/test-billing')
            ->assertOk();
    }

    public function test_middleware_respects_override_deny(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        UserPermission::create([
            'user_id' => $owner->id,
            'permission_key' => 'billing',
            'access_level' => AccessLevel::Deny,
        ]);

        $this->actingAs($owner)
            ->get('/test-billing')
            ->assertForbidden();
    }

    public function test_user_deletion_cascades_to_permission_overrides(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);

        UserPermission::create([
            'user_id' => $user->id,
            'permission_key' => 'billing',
            'access_level' => AccessLevel::Full,
        ]);

        $this->assertDatabaseCount('user_permissions', 1);

        $user->delete();

        $this->assertDatabaseCount('user_permissions', 0);
    }

    public function test_unique_constraint_on_user_and_permission_key(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);

        UserPermission::create([
            'user_id' => $user->id,
            'permission_key' => 'billing',
            'access_level' => AccessLevel::Full,
        ]);

        $this->expectException(QueryException::class);

        UserPermission::create([
            'user_id' => $user->id,
            'permission_key' => 'billing',
            'access_level' => AccessLevel::View,
        ]);
    }
}
