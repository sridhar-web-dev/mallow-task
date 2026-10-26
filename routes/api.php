<?php

use App\Http\Controllers\Api\StoreUsageController;
use Illuminate\Support\Facades\Route;

// Authenticate before throttling so the limiter can key on the merchant, not the IP.
Route::middleware(['auth.merchant', 'throttle:usage'])->group(function () {
    Route::post('/usage', StoreUsageController::class)->name('usage.store');
});
