<?php

use App\Models\SimulationRun;
use App\Simulation\Simulation;
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
});

Volt::route('invitations/{token}', 'pages.invitations.accept')
    ->name('invitations.accept');

require __DIR__.'/auth.php';
