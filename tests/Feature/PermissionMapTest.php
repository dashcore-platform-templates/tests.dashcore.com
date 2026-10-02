<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PermissionMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'permission:billing'])->get('/test-billing', fn () => 'ok');
        Route::middleware(['web', 'auth', 'permission:unknown-page'])->get('/test-unknown', fn () => 'ok');
    }

    public function test_dashboard_is_accessible_to_all_roles(): void
    {
        $service = app(PermissionService::class);

        $this->assertTrue($service->canAccess(UserRole::Owner, 'dashboard'));
        $this->assertTrue($service->canAccess(UserRole::Admin, 'dashboard'));
        $this->assertTrue($service->canAccess(UserRole::Finance, 'dashboard'));
        $this->assertTrue($service->canAccess(UserRole::Member, 'dashboard'));
    }

    public function test_billing_has_correct_access_levels(): void
    {
        $service = app(PermissionService::class);

        $this->assertEquals('full', $service->check(UserRole::Owner, 'billing'));
        $this->assertEquals('view', $service->check(UserRole::Admin, 'billing'));
        $this->assertEquals('full', $service->check(UserRole::Finance, 'billing'));
        $this->assertNull($service->check(UserRole::Member, 'billing'));
    }

    public function test_owner_has_full_access_to_billing(): void
    {
        $service = app(PermissionService::class);

        $this->assertTrue($service->hasFullAccess(UserRole::Owner, 'billing'));
        $this->assertFalse($service->hasViewAccess(UserRole::Owner, 'billing'));
    }

    public function test_admin_has_view_only_access_to_billing(): void
    {
        $service = app(PermissionService::class);

        $this->assertFalse($service->hasFullAccess(UserRole::Admin, 'billing'));
        $this->assertTrue($service->hasViewAccess(UserRole::Admin, 'billing'));
    }

    public function test_member_cannot_access_billing(): void
    {
        $service = app(PermissionService::class);

        $this->assertFalse($service->canAccess(UserRole::Member, 'billing'));
    }

    public function test_unknown_key_defaults_to_owner_and_admin_only(): void
    {
        $service = app(PermissionService::class);

        $this->assertTrue($service->canAccess(UserRole::Owner, 'some-unknown-key'));
        $this->assertTrue($service->canAccess(UserRole::Admin, 'some-unknown-key'));
        $this->assertFalse($service->canAccess(UserRole::Finance, 'some-unknown-key'));
        $this->assertFalse($service->canAccess(UserRole::Member, 'some-unknown-key'));
    }

    public function test_finance_page_is_hidden_from_admin_by_default(): void
    {
        $service = app(PermissionService::class);

        $this->assertTrue($service->canAccess(UserRole::Owner, 'finance'));
        $this->assertFalse($service->canAccess(UserRole::Admin, 'finance'));
        $this->assertTrue($service->canAccess(UserRole::Finance, 'finance'));
        $this->assertFalse($service->canAccess(UserRole::Member, 'finance'));
    }

    public function test_middleware_allows_owner_to_access_billing(): void
    {
        $user = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($user)
            ->get('/test-billing')
            ->assertOk();
    }

    public function test_middleware_allows_admin_to_access_billing_with_view(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($user)
            ->get('/test-billing')
            ->assertOk();
    }

    public function test_middleware_allows_finance_to_access_billing(): void
    {
        $user = User::factory()->create(['role' => UserRole::Finance]);

        $this->actingAs($user)
            ->get('/test-billing')
            ->assertOk();
    }

    public function test_middleware_blocks_member_from_billing(): void
    {
        $user = User::factory()->create(['role' => UserRole::Member]);

        $this->actingAs($user)
            ->get('/test-billing')
            ->assertForbidden();
    }

    public function test_middleware_default_deny_blocks_finance_on_unknown_page(): void
    {
        $user = User::factory()->create(['role' => UserRole::Finance]);

        $this->actingAs($user)
            ->get('/test-unknown')
            ->assertForbidden();
    }

    public function test_middleware_default_deny_allows_admin_on_unknown_page(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($user)
            ->get('/test-unknown')
            ->assertOk();
    }

    public function test_admin_blocked_from_finance_when_toggle_off(): void
    {
        $service = app(PermissionService::class);

        $this->assertFalse($service->canAccess(UserRole::Admin, 'finance'));
    }

    public function test_admin_granted_finance_access_when_toggle_on(): void
    {
        Setting::set('admin_can_access_finance', true);
        $service = app(PermissionService::class);

        $this->assertTrue($service->canAccess(UserRole::Admin, 'finance'));
        $this->assertEquals('full', $service->check(UserRole::Admin, 'finance'));
    }

    public function test_member_always_blocked_from_finance_regardless_of_toggle(): void
    {
        Setting::set('admin_can_access_finance', true);
        $service = app(PermissionService::class);

        $this->assertFalse($service->canAccess(UserRole::Member, 'finance'));
    }

    public function test_owner_and_finance_role_unaffected_by_toggle(): void
    {
        $service = app(PermissionService::class);

        $this->assertTrue($service->canAccess(UserRole::Owner, 'finance'));
        $this->assertTrue($service->canAccess(UserRole::Finance, 'finance'));

        Setting::set('admin_can_access_finance', true);

        $this->assertTrue($service->canAccess(UserRole::Owner, 'finance'));
        $this->assertTrue($service->canAccess(UserRole::Finance, 'finance'));
    }
}
