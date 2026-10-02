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

Volt::route('invitations/{token}', 'pages.invitations.accept')
    ->name('invitations.accept');

require __DIR__.'/auth.php';
