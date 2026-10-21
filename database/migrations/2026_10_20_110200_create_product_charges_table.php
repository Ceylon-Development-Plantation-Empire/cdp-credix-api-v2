<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every fee, penalty and Islamic charge of a product in one table. One row per charge type,
     * so a product cannot accidentally carry two processing fees.
     */
    public function up(): void
    {
        Schema::create('product_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('charge_type', 30);   // App\Enums\ChargeType
            $table->string('calculation', 30);   // App\Enums\ChargeCalculation
            $table->decimal('value', 12, 4);
            $table->decimal('min_amount', 15, 2)->nullable();
            $table->decimal('max_amount', 15, 2)->nullable();
            $table->string('collection', 30);    // App\Enums\ChargeCollection
            $table->string('recurrence', 30)->default('once'); // App\Enums\ChargeRecurrence
            // Islamic late charges are given to charity and never booked as income.
            $table->boolean('is_charity')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'charge_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_charges');
    }
};
