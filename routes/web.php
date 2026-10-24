<?php

use App\Models\Launch;
use App\Models\SimulationRun;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Simulation\Simulation;
use App\Workflows\Transfer;
use App\Workflows\WorkflowRunner;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified', 'permission:dashboard'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth', 'verified', 'permission:profile'])
    ->name('profile');

Route::view('admin/dashboard', 'dashboard')
    ->middleware(['auth', 'verified', 'admin'])
    ->name('admin.dashboard');

Route::view('team', 'team')
    ->middleware(['auth', 'verified', 'permission:team-management'])
    ->name('team');

Route::view('permissions', 'permissions')
    ->middleware(['auth', 'verified', 'permission:permissions'])
    ->name('permissions');

Route::middleware(['auth', 'verified', 'permission:fleet-tests'])->group(function () {
    Route::view('fleet', 'fleet.index')->name('fleet.index');
    Route::view('fleet/{key}', 'fleet.show')->name('fleet.show');
    Route::view('scans/{scan}', 'scans.show')->whereNumber('scan')->name('scans.show');
    Route::view('builds/{build}', 'builds.show')->whereNumber('build')->name('builds.show');

    Route::view('library', 'library.index')->name('library.index');
    Route::get('library/{key}', fn (string $key) => view('library.app', ['key' => $key]))->name('library.app');
    Route::get('library/{key}/file', fn (string $key) => view('library.file', ['key' => $key]))->name('library.file');

    Route::view('proving-ground', 'simulation.index')->name('simulation.index');
    Route::get('proving-ground/runs/{run}/{file}', function (SimulationRun $run, string $file, Simulation $simulation) {
        $path = $simulation->screenshotDirectory($run).'/'.$file;

        abort_if($run->screenshots_pruned || ! is_file($path), 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=86400, immutable']);
    })->where('file', '[0-9]{3}\\.jpg')->name('simulation.screenshot');

    // The client journey map, and each step's screenshot from the launch it
    // draws — served only from under the screenshots folder.
    Route::view('client-journey', 'simulation.journey-map')->name('simulation.journey-map');
    Route::get('client-journey/{launch}/{stage}/{walk}/{file}', function (Launch $launch, string $stage, int $walk, string $file) {
        $shots = collect($launch->stages)->firstWhere('stage', $stage)['walks'][$walk]['shots'] ?? null;
        $root = realpath((string) config('simulation.screenshots'));
        $path = is_string($shots) ? realpath(rtrim($shots, '/').'/'.$file) : false;

        abort_unless($root !== false && $path !== false && str_starts_with($path, $root.DIRECTORY_SEPARATOR), 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=86400, immutable']);
    })->where(['stage' => '[a-z0-9-]+', 'walk' => '[0-9]+', 'file' => '[0-9]{3}\\.jpg'])->name('simulation.journey-map.shot');
});

// Browser-test workflows: built and kept per install, owners and admins only.
Route::middleware(['auth', 'verified', 'permission:workflows'])->group(function () {
    Route::view('workflows', 'workflows.index')->name('workflows.index');
    Route::view('workflows/new', 'workflows.wizard')->name('workflows.create');
    Route::view('workflows/health', 'workflows.health')->name('workflows.health');
    Route::get('workflows/{workflow}', fn (Workflow $workflow) => view('workflows.show', ['workflow' => $workflow]))->whereNumber('workflow')->name('workflows.show');
    Route::get('workflows/{workflow}/edit', fn (Workflow $workflow) => view('workflows.wizard', ['workflow' => $workflow]))->whereNumber('workflow')->name('workflows.edit');
    Route::get('workflows/export', fn () => Transfer::download(Workflow::query()->orderBy('name')->get()))->name('workflows.export');
    Route::get('workflows/{workflow}/export', fn (Workflow $workflow) => Transfer::download(collect([$workflow])))->whereNumber('workflow')->name('workflows.export-one');
    Route::get('workflows/runs/{run}/{file}', function (WorkflowRun $run, string $file) {
        $path = WorkflowRunner::screenshotDirectory($run).'/'.$file;

        abort_if($run->screenshots_pruned || ! is_file($path), 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=86400, immutable']);
    })->where('file', '[0-9]{3}\\.jpg')->name('workflows.screenshot');
});

Volt::route('invitations/{token}', 'pages.invitations.accept')
    ->name('invitations.accept');

require __DIR__.'/auth.php';
