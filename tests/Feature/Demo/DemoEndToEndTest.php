<?php

namespace Tests\Feature\Demo;

use App\Models\User;
use App\Services\NavigationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDemoScratch;
use Tests\TestCase;

/**
 * On a fresh database, one command fills every page in the navigation with
 * believable data, labelled as demo — and a second takes it all away again.
 */
class DemoEndToEndTest extends TestCase
{
    use RefreshDatabase, UsesDemoScratch;

    /**
     * What each page in the navigation shows with the demo in, and what it
     * shows once the demo is wiped.
     *
     * @var array<string, array{filled: list<string>, empty: list<string>}>
     */
    private const PAGES = [
        'dashboard' => ['filled' => ['Demo Sales'], 'empty' => ['No completed scans yet.']],
        'fleet.index' => ['filled' => ['Demo Sales', 'Demo Finance', 'Demo Website'], 'empty' => ['No apps in the fleet yet.']],
        'library.index' => ['filled' => ['Demo Sales', 'Demo Finance', 'Demo Website'], 'empty' => ['No apps in the library yet.']],
        'simulation.index' => ['filled' => ['Timed out after 10s', 'Nothing shows it is working'], 'empty' => ['Nothing has run yet.']],
        'simulation.journey-map' => ['filled' => ['First sign-in failed after 2 of 5 steps.'], 'empty' => ['No launch has been flown yet.']],
        'workflows.index' => ['filled' => ['Website: lets-talk form takes an enquiry', 'Sales stand-in: a rep signs in'], 'empty' => ['never run']],
        'workflows.health' => ['filled' => ['Website: main pages answer', 'Demo run — nothing was opened.'], 'empty' => ['Website: main pages answer']],
        'team' => ['filled' => [], 'empty' => []],
        'permissions' => ['filled' => [], 'empty' => []],
    ];

    public function test_one_command_fills_every_nav_page_and_a_second_empties_them(): void
    {
        $owner = User::factory()->owner()->create();
        $routes = app(NavigationRegistry::class)->itemsForUser($owner)->flatten(1)->pluck('route')->push('dashboard')->unique()->values();

        $this->assertEqualsCanonicalizing(array_keys(self::PAGES), $routes->all(), 'Every page in the navigation is checked here.');

        $this->artisan('tests:demo')->assertSuccessful();

        foreach (self::PAGES as $route => $page) {
            $this->actingAs($owner)->get(route($route))
                ->assertOk()
                ->assertSee('data-demo-banner', false)
                ->assertSee($page['filled']);
        }

        $this->artisan('tests:demo --wipe')->assertSuccessful();

        foreach (self::PAGES as $route => $page) {
            $this->actingAs($owner)->get(route($route))
                ->assertOk()
                ->assertDontSee('data-demo-banner', false)
                ->assertDontSee(['Demo Sales', 'Timed out after 10s', 'First sign-in failed', 'Demo run — nothing was opened.'])
                ->assertSee($page['empty']);
        }
    }
}
