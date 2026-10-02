<?php

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
});

Volt::route('invitations/{token}', 'pages.invitations.accept')
    ->name('invitations.accept');

require __DIR__.'/auth.php';
