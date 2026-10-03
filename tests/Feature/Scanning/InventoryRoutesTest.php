<?php

namespace Tests\Feature\Scanning;

use App\Enums\SurfaceKind;
use App\Scanning\Inventory\Inventory;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class InventoryRoutesTest extends TestCase
{
    public function test_livewire_page_routes_that_except_vendor_drops_are_listed_once(): void
    {
        $page = ['method' => 'GET|HEAD', 'uri' => 'transactions', 'name' => 'transactions.index', 'action' => 'Livewire\\Mechanisms\\HandleRouting\\LivewirePageController', 'middleware' => ['web']];
        $own = ['method' => 'GET|HEAD', 'uri' => 'reports', 'name' => 'reports', 'action' => 'ReportController@index', 'middleware' => ['web']];
        $vendor = ['method' => 'GET|HEAD', 'uri' => 'horizon', 'name' => 'horizon', 'action' => 'Laravel\\Horizon\\Http\\Controllers\\HomeController@index', 'middleware' => ['web']];

        Process::fake([
            '*--except-vendor*' => Process::result(json_encode([$own])),
            '*route:list*' => Process::result(json_encode([$own, $page, $vendor])),
        ]);

        $routes = collect(app(Inventory::class)->discover(dirname(__DIR__, 2).'/Fixtures/sample-app', PHP_BINARY, []))
            ->where('kind', SurfaceKind::Route)
            ->pluck('identifier')
            ->all();

        $this->assertSame(['GET reports', 'GET transactions'], $routes);
    }

    public function test_when_the_full_list_fails_the_apps_own_routes_still_count(): void
    {
        Process::fake([
            '*--except-vendor*' => Process::result(json_encode([['method' => 'GET|HEAD', 'uri' => 'reports', 'middleware' => []]])),
            '*route:list*' => Process::result('boom', exitCode: 1),
        ]);

        $routes = collect(app(Inventory::class)->discover(dirname(__DIR__, 2).'/Fixtures/sample-app', PHP_BINARY, []))
            ->where('kind', SurfaceKind::Route)
            ->pluck('identifier')
            ->all();

        $this->assertSame(['GET reports'], $routes);
    }
}
