<?php

namespace Tests\Unit\Scanning;

use App\Enums\SurfaceKind;
use App\Scanning\Inventory\CodeInventory;
use App\Scanning\Inventory\DiscoveredSurface;
use PHPUnit\Framework\TestCase;

class CodeInventoryTest extends TestCase
{
    /** @var array<string, DiscoveredSurface> */
    private array $surfaces;

    private CodeInventory $inventory;

    protected function setUp(): void
    {
        $this->inventory = new CodeInventory;
        $found = $this->inventory->discover(dirname(__DIR__, 2).'/Fixtures/sample-app');

        $this->surfaces = [];
        foreach ($found as $surface) {
            $this->surfaces[$surface->identifier] = $surface;
        }
    }

    public function test_every_public_method_with_a_body_is_a_surface_with_its_body_lines(): void
    {
        $handle = $this->surfaces['App\Jobs\SendReport::handle'];

        $this->assertSame(SurfaceKind::Job, $handle->kind);
        $this->assertSame('app/Jobs/SendReport.php', $handle->file);
        $this->assertSame(11, $handle->startLine, 'starts at the first statement, not the signature');
        $this->assertSame(13, $handle->endLine);
        $this->assertArrayHasKey('App\Jobs\SendReport::failed', $this->surfaces, 'a failure path is its own surface');
    }

    public function test_constructors_and_empty_methods_are_not_surfaces(): void
    {
        $this->assertArrayNotHasKey('App\Jobs\SendReport::__construct', $this->surfaces);
        $this->assertArrayNotHasKey('App\Http\Controllers\ReportController::empty', $this->surfaces);
        $this->assertSame(SurfaceKind::Controller, $this->surfaces['App\Http\Controllers\ReportController::index']->kind);
    }

    public function test_model_relations_are_left_out_but_model_logic_is_not(): void
    {
        $this->assertArrayNotHasKey('App\Models\Invoice::lines', $this->surfaces);
        $this->assertSame(SurfaceKind::Model, $this->surfaces['App\Models\Invoice::total']->kind);
    }

    public function test_providers_are_wiring_not_surfaces(): void
    {
        $this->assertArrayNotHasKey('App\Providers\AppServiceProvider::boot', $this->surfaces);
    }

    public function test_livewire_components_are_named_as_livewire_names_them_and_hooks_are_not_actions(): void
    {
        $component = $this->surfaces['App\Livewire\Settings\Index'];

        $this->assertSame(SurfaceKind::Component, $component->kind);
        $this->assertSame('settings', $component->label, 'a trailing Index is dropped');
        $this->assertSame(['App\Livewire\Settings\Index', 'settings'], $component->match);

        $save = $this->surfaces['App\Livewire\Settings\Index::save'];
        $this->assertSame(SurfaceKind::Action, $save->kind);
        $this->assertSame(['App\Livewire\Settings\Index@save', 'settings@save'], $save->match);

        $this->assertArrayNotHasKey('App\Livewire\Settings\Index::mount', $this->surfaces);
        $this->assertArrayNotHasKey('App\Livewire\Settings\Index::updatedName', $this->surfaces);
        $this->assertArrayNotHasKey('App\Livewire\Settings\Index::render', $this->surfaces);

        $this->assertSame('settings.ai-settings', $this->surfaces['App\Livewire\Settings\AiSettings']->label);
    }

    public function test_a_components_actions_include_the_ones_its_traits_give_it(): void
    {
        $saveNotes = $this->surfaces['App\Livewire\Settings\Index::saveNotes'];

        $this->assertSame(SurfaceKind::Action, $saveNotes->kind);
        $this->assertSame('settings@saveNotes', $saveNotes->label);
        $this->assertSame(['App\Livewire\Settings\Index@saveNotes', 'settings@saveNotes'], $saveNotes->match);
        $this->assertSame('app/Livewire/Concerns/HasNotes.php', $saveNotes->file, 'its lines are the trait\'s');
        $this->assertSame(18, $saveNotes->startLine);

        $this->assertSame('settings@undo', $this->surfaces['App\Livewire\Settings\Index::undo']->label, 'a trait of a trait counts too');
        $this->assertSame('app/Livewire/Settings/Index.php', $this->surfaces['App\Livewire\Settings\Index::save']->file, 'the component\'s own method wins');
        $this->assertArrayNotHasKey('App\Livewire\Settings\Index::mountHasNotes', $this->surfaces, 'a trait\'s lifecycle hook is not an action');
    }

    public function test_a_trait_only_components_use_is_listed_through_them_not_on_its_own(): void
    {
        $this->assertArrayNotHasKey('App\Livewire\Concerns\HasNotes::saveNotes', $this->surfaces);
        $this->assertArrayNotHasKey('App\Livewire\Concerns\KeepsHistory::undo', $this->surfaces);
    }

    public function test_a_trait_other_classes_use_keeps_its_own_surfaces(): void
    {
        $this->assertSame('FormatsMoney::money', $this->surfaces['App\Concerns\FormatsMoney::money']->label);
        $this->assertArrayNotHasKey('App\Services\Pricing::money', $this->surfaces, 'not repeated on every class that uses it');
    }

    public function test_a_file_that_does_not_parse_is_reported_not_fatal(): void
    {
        $this->assertCount(1, $this->inventory->errors);
        $this->assertStringContainsString('app/Services/Broken.php', $this->inventory->errors[0]);
    }

    public function test_an_app_without_an_app_directory_has_no_code_surfaces(): void
    {
        $this->assertSame([], (new CodeInventory)->discover(sys_get_temp_dir().'/does-not-exist-'.uniqid()));
    }
}
