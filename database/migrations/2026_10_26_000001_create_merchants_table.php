<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            // Invoices are single-currency per merchant; amounts everywhere are stored in minor units.
            $table->char('currency', 3)->default('INR');
            // SHA-256 of the merchant's API key. The plaintext key is shown once and never stored.
            $table->char('api_key_hash', 64)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
