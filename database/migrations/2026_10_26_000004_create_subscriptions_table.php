<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Denormalised from customers so merchant-scoped queries skip a join.
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            // App\Enums\SubscriptionStatus (active|cancelled).
            $table->string('status', 16);
            $table->date('started_on');
            // Cycles are anchored to calendar months/years. A mid-cycle start makes the first
            // period partial, which is where proration comes from. Both dates are inclusive.
            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->date('cancelled_on')->nullable();
            $table->timestamps();

            // The cycle-end scheduler scans for "active subscriptions whose period ended".
            $table->index(['status', 'current_period_end']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
