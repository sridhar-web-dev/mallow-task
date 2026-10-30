<?php

use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// A thin client over the JSON API: the page holds the merchant's API key in sessionStorage
// and renders GET /api/merchants/{id}/dashboard. All numbers come from the API, none from the view.
Route::get('/dashboard', fn () => view('dashboard', [
    // Offered as a one-click shortcut only in local environments.
    'demoKey' => app()->isLocal() ? DemoSeeder::DEMO_API_KEY : null,
    'system' => [
        'cache_store' => config('cache.default'),
        'plan_catalog_ttl' => config('billing.cache.plan_catalog_ttl'),
        'dashboard_ttl' => config('billing.cache.dashboard_ttl'),
        'aggregation_chunk' => config('billing.aggregation.chunk_size'),
        'late_days' => config('billing.usage.late_arrival_days'),
        'queue' => config('queue.default'),
        'rate_merchant' => config('billing.usage.rate_limit_per_merchant'),
        'rate_customer' => config('billing.usage.rate_limit_per_customer'),
    ],
]))->name('dashboard');
