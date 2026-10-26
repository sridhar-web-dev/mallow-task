<?php

namespace App\Services;

use App\Data\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rolls raw usage_events up into daily_usage (one row per customer per day).
 *
 * Every write is an upsert of the absolute SUM recomputed from the raw rows, never an
 * increment. Re-running for a day that is already aggregated, or that has received late
 * events since, always converges on the right total. This is what makes the jobs safe to
 * retry and to run on overlapping schedules.
 */
class UsageAggregator
{
    /**
     * Aggregates every customer with usage on $date, in chunks of customer IDs.
     *
     * The query uses keyset pagination (customer_id > last seen) rather than OFFSET, so each
     * chunk is a bounded range scan of the (usage_date, customer_id) index however deep
     * into the day it is.
     *
     * @return int customer-days written
     */
    public function aggregateDate(CarbonImmutable $date, ?int $chunkSize = null): int
    {
        $chunkSize ??= config('billing.aggregation.chunk_size');
        $day = $date->toDateString();
        $afterCustomerId = 0;
        $written = 0;

        do {
            $rows = DB::table('usage_events')
                ->where('usage_date', $day)
                ->where('customer_id', '>', $afterCustomerId)
                ->groupBy('customer_id', 'merchant_id')
                ->orderBy('customer_id')
                ->limit($chunkSize)
                ->selectRaw('customer_id, merchant_id, SUM(quantity) AS total_units, COUNT(*) AS event_count')
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $this->upsert($rows->map(fn ($row) => [
                'customer_id' => $row->customer_id,
                'merchant_id' => $row->merchant_id,
                'usage_date' => $day,
                'total_units' => $row->total_units,
                'event_count' => $row->event_count,
            ])->all());

            $written += $rows->count();
            $afterCustomerId = (int) $rows->last()->customer_id;
        } while ($rows->count() === $chunkSize);

        return $written;
    }

    /**
     * Re-aggregates one customer across a date range. The invoice job runs this just before
     * pricing a cycle, so the invoice never depends on the schedule having caught up.
     *
     * @return int customer-days written
     */
    public function aggregateCustomer(int $customerId, DateRange $range): int
    {
        $rows = DB::table('usage_events')
            ->where('customer_id', $customerId)
            ->whereBetween('usage_date', [$range->start->toDateString(), $range->end->toDateString()])
            ->groupBy('usage_date', 'merchant_id')
            ->selectRaw('usage_date, merchant_id, SUM(quantity) AS total_units, COUNT(*) AS event_count')
            ->get();

        $this->upsert($rows->map(fn ($row) => [
            'customer_id' => $customerId,
            'merchant_id' => $row->merchant_id,
            'usage_date' => $row->usage_date,
            'total_units' => $row->total_units,
            'event_count' => $row->event_count,
        ])->all());

        return $rows->count();
    }

    private function upsert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();

        foreach ($rows as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        DB::table('daily_usage')->upsert(
            $rows,
            uniqueBy: ['customer_id', 'usage_date'],
            update: ['total_units', 'event_count', 'updated_at'],
        );
    }
}
