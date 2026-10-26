<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            // The merchant's own identifier for this customer, which is what API callers send.
            $table->string('external_ref', 100);
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
