<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 3);
            $table->unsignedBigInteger('base_amount_minor');
            $table->unsignedBigInteger('overage_amount_minor');
            $table->unsignedBigInteger('total_minor');
            // App\Enums\InvoiceStatus (issued|paid|void).
            $table->string('status', 16);
            $table->timestamp('issued_at');
            $table->timestamps();

            // One invoice per subscription cycle, so a retried billing job cannot double-invoice.
            $table->unique(['subscription_id', 'period_start']);
            $table->index(['merchant_id', 'period_start']);
            $table->index('customer_id');
        });

        Schema::create('invoice_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_period_id')->nullable()->constrained()->nullOnDelete();
            // App\Enums\LineItemType (base|overage).
            $table->string('type', 16);
            $table->string('description');
            // The part of the cycle this line covers, so a mid-cycle plan change shows up as two segments.
            $table->date('segment_start');
            $table->date('segment_end');
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('unit_price_micros');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_line_items');
        Schema::dropIfExists('invoices');
    }
};
