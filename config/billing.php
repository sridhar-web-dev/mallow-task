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

];
