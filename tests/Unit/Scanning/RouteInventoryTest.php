<?php

namespace Tests\Unit\Scanning;

use App\Enums\SurfaceKind;
use App\Scanning\Inventory\RouteInventory;
use PHPUnit\Framework\TestCase;

class RouteInventoryTest extends TestCase
{
    public function test_each_method_of_each_app_route_is_one_surface(): void
    {
        $json = json_encode([
            ['domain' => null, 'method' => 'GET|HEAD', 'uri' => '/', 'name' => null, 'action' => 'Closure', 'middleware' => ['web']],
            ['domain' => null, 'method' => 'GET|HEAD|POST', 'uri' => 'reports', 'name' => 'reports', 'action' => 'ReportController@index', 'middleware' => ['web', 'auth']],
            ['domain' => null, 'method' => 'POST', 'uri' => 'livewire-abc/update', 'name' => null, 'action' => 'x', 'middleware' => []],
            ['domain' => null, 'method' => 'GET|HEAD', 'uri' => 'up', 'name' => null, 'action' => 'Closure', 'middleware' => []],
        ]);

        $surfaces = (new RouteInventory)->fromJson("Some boot warning\n".$json);

        $this->assertSame(['GET /', 'GET reports', 'POST reports'], array_map(fn ($s) => $s->identifier, $surfaces));
        $this->assertSame(SurfaceKind::Route, $surfaces[0]->kind);
        $this->assertSame('GET /reports', $surfaces[1]->label);
        $this->assertSame(['GET reports'], $surfaces[1]->match);
        $this->assertSame(['web', 'auth'], $surfaces[1]->meta['middleware']);
        $this->assertSame('reports', $surfaces[1]->meta['name']);
    }

    public function test_output_that_is_not_json_yields_no_routes(): void
    {
        $this->assertSame([], (new RouteInventory)->fromJson('Your application doesn\'t have any routes.'));
    }
}
