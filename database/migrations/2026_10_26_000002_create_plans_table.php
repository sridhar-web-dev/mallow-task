<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // App\Enums\BillingCycle (monthly|yearly). Stored as a string so new cycles need no ALTER.
            $table->string('billing_cycle', 16);
            // Minor units (paise/cents) to avoid floating-point money.
            $table->unsignedBigInteger('base_price_minor');
            $table->unsignedBigInteger('included_units');
            // Per-unit overage price in millionths of the currency unit (micros), so sub-paisa rates
            // such as 0.0025 per API call stay exact. 1 paisa = 10_000 micros.
            $table->unsignedBigInteger('overage_rate_micros');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['merchant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
