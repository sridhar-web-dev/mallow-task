<?php

return [

    'usage' => [
        // Two limits apply to POST /usage. The merchant limit protects the platform. The
        // per-customer limit stops one runaway integration from using up the merchant's
        // whole allowance.
        'rate_limit_per_merchant' => (int) env('USAGE_RATE_LIMIT_PER_MERCHANT', 6000),
        'rate_limit_per_customer' => (int) env('USAGE_RATE_LIMIT_PER_CUSTOMER', 1200),

        // Upper bound on a single event's quantity. This catches client bugs (for example
        // sending a timestamp as a quantity) and keeps values well inside the column's range.
        'max_quantity' => (int) env('USAGE_MAX_QUANTITY', 1_000_000),

        // How many days in the past a usage_date may be. Late events are accepted only
        // within this window, so a cycle is final once its invoice job runs after the window.
        'late_arrival_days' => (int) env('USAGE_LATE_ARRIVAL_DAYS', 3),
    ],

    'aggregation' => [
        // Customers rolled up per query and upsert. This bounds memory and statement size
        // however many customers were active that day.
        'chunk_size' => (int) env('USAGE_AGGREGATION_CHUNK', 1000),
    ],

    'cache' => [
        // Backstop only. Plan writes through Eloquent invalidate immediately (PlanObserver).
        'plan_catalog_ttl' => (int) env('PLAN_CATALOG_TTL', 3600),
        // Dashboard results are also invalidated whenever their inputs change (DashboardCache).
        'dashboard_ttl' => (int) env('DASHBOARD_CACHE_TTL', 300),
    ],

    'dashboard' => [
        'top_customers' => 5,
        // Flag a customer when this month-to-date usage is below this share of last month's.
        'churn_drop_threshold_percent' => 50,
        'churn_list_limit' => 50,
        'rate_limit_per_minute' => (int) env('DASHBOARD_RATE_LIMIT', 60),
    ],

    'invoicing' => [
        // Due subscriptions read per page when fanning out one billing job per subscription.
        'chunk_size' => (int) env('BILLING_CHUNK', 500),
    ],

];
