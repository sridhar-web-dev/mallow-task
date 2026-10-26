<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row for each continuous stretch of time that a subscription spent on a single plan.
 * An upgrade or downgrade closes the open row and opens a new one, so a billing cycle is
 * billed as the sum of its overlapping segments (requirement 8).
 *
 * Pricing is copied (snapshotted) from the plan when the segment opens. A merchant
 * editing a plan's price therefore never changes what an in-flight cycle is billed,
 * and billing never depends on the plan cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plan_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            // Inclusive start. A plan change takes effect from the start of a day, because usage is
            // metered at day granularity.
            $table->date('starts_on');
            // Inclusive end. Null means this is the currently open segment.
            $table->date('ends_on')->nullable();

            $table->string('billing_cycle', 16);
            $table->unsignedBigInteger('base_price_minor');
            $table->unsignedBigInteger('included_units');
            $table->unsignedBigInteger('overage_rate_micros');
            $table->timestamps();

            $table->index(['subscription_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_periods');
    }
};
