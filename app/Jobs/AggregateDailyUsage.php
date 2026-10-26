<?php

namespace App\Jobs;

use App\Services\UsageAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Rolls up one day of raw usage for every customer. It is unique per date, so overlapping
 * schedules (hourly for today, plus the late-arrival window) never run the same day twice
 * at once. Retrying is safe because the aggregator writes absolute totals.
 */
class AggregateDailyUsage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly string $date) {}

    public function uniqueId(): string
    {
        return $this->date;
    }

    public function handle(UsageAggregator $aggregator): void
    {
        $written = $aggregator->aggregateDate(CarbonImmutable::parse($this->date));

        Log::info('Aggregated daily usage', ['date' => $this->date, 'customer_days' => $written]);
    }
}
