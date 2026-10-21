<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which collateral types a product accepts and how much can be lent against each
     * (loan-to-value). Replaces the old lending_mortgages "plans".
     */
    public function up(): void
    {
        Schema::create('product_collateral_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('collateral_type', 30); // App\Enums\CollateralType
            $table->decimal('max_ltv_percent', 5, 2);
            $table->boolean('is_mandatory')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'collateral_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_collateral_rules');
    }
};
