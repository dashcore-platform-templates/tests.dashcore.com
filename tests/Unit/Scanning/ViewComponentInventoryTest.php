<?php

namespace Tests\Unit\Scanning;

use App\Enums\SurfaceKind;
use App\Scanning\Inventory\DiscoveredSurface;
use App\Scanning\Inventory\ViewComponentInventory;
use PHPUnit\Framework\TestCase;

class ViewComponentInventoryTest extends TestCase
{
    /** @var array<string, DiscoveredSurface> */
    private array $surfaces;

    protected function setUp(): void
    {
        $this->surfaces = [];
        foreach ((new ViewComponentInventory)->discover(dirname(__DIR__, 2).'/Fixtures/sample-app') as $surface) {
            $this->surfaces[$surface->identifier] = $surface;
        }
    }

    public function test_a_class_based_volt_component_and_its_actions_are_found(): void
    {
        $component = $this->surfaces['resources/views/livewire/pages/auth/login.blade.php'];

        $this->assertSame(SurfaceKind::Component, $component->kind);
        $this->assertSame('pages.auth.login', $component->label);
        $this->assertContains('pages.auth.login', $component->match);

        $login = $this->surfaces['resources/views/livewire/pages/auth/login.blade.php@login'];
        $this->assertSame(SurfaceKind::Action, $login->kind);
        $this->assertContains('pages.auth.login@login', $login->match);

        $this->assertArrayNotHasKey('resources/views/livewire/pages/auth/login.blade.php@mount', $this->surfaces);
        $this->assertArrayNotHasKey('resources/views/livewire/pages/auth/login.blade.php@normalise', $this->surfaces, 'a closure inside a method is not an action');
    }

    public function test_class_component_actions_carry_their_body_lines_for_mutation(): void
    {
        $login = $this->surfaces['resources/views/livewire/pages/auth/login.blade.php@login'];

        $this->assertSame(13, $login->startLine, 'the file\'s own line numbers, template and all');
        $this->assertSame(16, $login->endLine);
        $this->assertNotNull($login->hash);

        $increment = $this->surfaces['resources/views/pages/counter.blade.php@increment'];
        $this->assertFalse($increment->hasLines(), 'a functional closure has no method body to swap');
    }

    public function test_functional_volt_closures_are_actions_and_the_pages_namespace_is_a_name(): void
    {
        $component = $this->surfaces['resources/views/pages/counter.blade.php'];

        $this->assertSame(['counter', 'pages::counter', 'pages.counter'], $component->match);
        $this->assertArrayHasKey('resources/views/pages/counter.blade.php@increment', $this->surfaces);
        $this->assertArrayHasKey('resources/views/pages/counter.blade.php@reset', $this->surfaces);
    }

    public function test_plain_blade_views_are_not_components(): void
    {
        $this->assertArrayNotHasKey('resources/views/plain.blade.php', $this->surfaces);
    }
}
