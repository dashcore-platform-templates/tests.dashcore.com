<?php

use App\Http\Controllers\Bridge\StatusController;
use Illuminate\Support\Facades\Route;

// How every app's suite stands, for fleets' Standards & tests tab.
Route::prefix('bridge/v1')->middleware(['bridge.auth', 'bridge.scope:tests.status.read'])->group(function (): void {
    Route::get('status', StatusController::class)->name('bridge.status');
});
