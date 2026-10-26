<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw, append-only usage ledger. This is the hot write path and the table that grows
 * to 50L+ (5M+) rows. It is designed to be written fast and read rarely:
 *
 *  - No foreign keys. Each FK costs a lookup per insert, and MySQL forbids FKs on
 *    partitioned tables. Integrity is checked once in the request layer.
 *  - No updated_at. Rows are immutable, and corrections arrive as new events.
 *  - Only three secondary indexes: the idempotency guard and the two aggregation access
 *    paths. Dashboards never read this table; they read daily_usage.
 *
 * See README "Scaling usage_events" for monthly RANGE partitioning on usage_date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('customer_id');
            // Client-supplied key. A retried request with the same key is a no-op (insertOrIgnore).
            $table->string('idempotency_key', 64);
            $table->unsignedInteger('quantity');
            // The billing day the usage belongs to (UTC), which may differ from when it arrived.
            $table->date('usage_date');
            $table->timestamp('created_at')->useCurrent();

            // Idempotency keys are scoped to a merchant, so two tenants may reuse the same key.
            $table->unique(['merchant_id', 'idempotency_key']);
            // Daily rollup: "every customer with events on day X", read in customer_id order
            // with keyset pagination. Starts with the date, so it also matches monthly partitions.
            $table->index(['usage_date', 'customer_id']);
            // Invoice-time re-aggregation: one customer across the dates of one cycle.
            $table->index(['customer_id', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
