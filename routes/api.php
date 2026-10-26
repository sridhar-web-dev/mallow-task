<?php

use App\Http\Controllers\Api\ListPlansController;
use App\Http\Controllers\Api\ShowDashboardController;
use App\Http\Controllers\Api\StoreUsageController;
use Illuminate\Support\Facades\Route;

// Authenticate before throttling so the limiter can key on the merchant, not the IP.
Route::middleware(['auth.merchant', 'throttle:usage'])->group(function () {
    Route::post('/usage', StoreUsageController::class)->name('usage.store');
});

Route::middleware(['auth.merchant', 'throttle:merchant-reads'])->group(function () {
    Route::get('/plans', ListPlansController::class)->name('plans.index');
    Route::get('/merchants/{merchantId}/dashboard', ShowDashboardController::class)
        ->whereNumber('merchantId')
        ->name('merchants.dashboard');
});
