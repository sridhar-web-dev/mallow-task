<?php

use App\Jobs\AggregateDailyUsage;
use App\Jobs\GenerateDueInvoices;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('usage:aggregate {date? : Y-m-d; defaults to today plus the late-arrival window}', function (?string $date = null) {
    $dates = $date !== null
        ? [CarbonImmutable::parse($date)]
        : array_map(
            fn (int $daysAgo) => CarbonImmutable::today()->subDays($daysAgo),
            range(0, config('billing.usage.late_arrival_days')),
        );

    foreach ($dates as $day) {
        AggregateDailyUsage::dispatch($day->toDateString());
        $this->info("Queued usage aggregation for {$day->toDateString()}");
    }
})->purpose('Roll raw usage events up into daily_usage');

Artisan::command('billing:run', function () {
    GenerateDueInvoices::dispatch();
    $this->info('Queued invoicing for all subscriptions whose cycle has closed.');
})->purpose('Generate invoices for closed billing cycles');

// Hourly keeps the dashboard fresh for today. Re-running the late-arrival window picks up
// events that arrived late for earlier days. Absolute-total upserts make the overlap harmless.
Schedule::command('usage:aggregate')->hourly()->withoutOverlapping();

Schedule::command('billing:run')->dailyAt('01:00')->withoutOverlapping();
