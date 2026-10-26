<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rollup with one row for each customer and day, rebuilt from usage_events by the aggregation job.
 * This is a deliberate denormalisation: it has about 30 rows per customer per month instead
 * of thousands of raw events, and both billing and the dashboard read only this table.
 *
 * The job writes absolute totals (SUM recomputed from the raw rows) via upsert, never
 * "+= delta". Re-running it for the same day is therefore always safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->date('usage_date');
            $table->unsignedBigInteger('total_units');
            $table->unsignedInteger('event_count');
            $table->timestamps();

            // Upsert target, and also serves billing's range scan for one customer.
            $table->unique(['customer_id', 'usage_date']);
            // Covering index for the dashboard: per-merchant date-range GROUP BY customer
            // with SUM(total_units), answered without touching the table rows.
            $table->index(['merchant_id', 'usage_date', 'customer_id', 'total_units'], 'daily_usage_dashboard_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_usage');
    }
};
