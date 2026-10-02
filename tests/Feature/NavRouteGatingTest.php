<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\NavigationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavRouteGatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_route_uses_permission_middleware(): void
    {
        $member = User::factory()->create(['role' => UserRole::Member]);

        $this->actingAs($member)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_profile_route_uses_permission_middleware(): void
    {
        $member = User::factory()->create(['role' => UserRole::Member]);

        $this->actingAs($member)
            ->get(route('profile'))
            ->assertOk();
    }

    public function test_nav_item_with_permission_key_visible_to_allowed_role(): void
    {
        $registry = app(NavigationRegistry::class);
        $registry->register('Test', 'Dashboard Link', 'dashboard', null, 0, 'dashboard');

        $items = $registry->itemsFor(UserRole::Member);

        $this->assertTrue($items->has('Test'));
        $this->assertEquals('Dashboard Link', $items['Test']->first()['label']);
    }

    public function test_nav_item_with_permission_key_hidden_from_disallowed_role(): void
    {
        $registry = app(NavigationRegistry::class);
        $registry->register('Test', 'Billing Link', 'billing', null, 0, 'billing');

        $items = $registry->itemsFor(UserRole::Member);

        $this->assertFalse($items->has('Test'));
    }

    public function test_nav_item_without_permission_key_visible_to_owner(): void
    {
        $registry = app(NavigationRegistry::class);
        $registry->register('Test', 'Legacy Link', 'some.route', null, 0);

        $items = $registry->itemsFor(UserRole::Owner);

        $this->assertTrue($items->has('Test'));
    }

    public function test_nav_item_without_permission_key_visible_to_admin(): void
    {
        $registry = app(NavigationRegistry::class);
        $registry->register('Test', 'Legacy Link', 'some.route', null, 0);

        $items = $registry->itemsFor(UserRole::Admin);

        $this->assertTrue($items->has('Test'));
    }

    public function test_nav_item_without_permission_key_hidden_from_finance(): void
    {
        $registry = app(NavigationRegistry::class);
        $registry->register('Test', 'Legacy Link', 'some.route', null, 0);

        $items = $registry->itemsFor(UserRole::Finance);

        $this->assertFalse($items->has('Test'));
    }

    public function test_nav_item_without_permission_key_hidden_from_member(): void
    {
        $registry = app(NavigationRegistry::class);
        $registry->register('Test', 'Legacy Link', 'some.route', null, 0);

        $items = $registry->itemsFor(UserRole::Member);

        $this->assertFalse($items->has('Test'));
    }

    public function test_has_items_for_respects_permissions(): void
    {
        $registry = app(NavigationRegistry::class);
        $registry->register('Test', 'Billing Link', 'billing', null, 0, 'billing');

        $this->assertTrue($registry->hasItemsFor(UserRole::Owner));
        $this->assertTrue($registry->hasItemsFor(UserRole::Finance));
        $this->assertFalse($registry->hasItemsFor(UserRole::Member));
    }
}
