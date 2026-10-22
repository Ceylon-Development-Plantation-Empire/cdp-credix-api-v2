<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fees and charges a product carries. One row per charge type, so a product cannot
 * accidentally carry two processing fees. A loan copies these terms into its terms snapshot at
 * submission and approval, so changing a product's charges never changes a running loan.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('product_charges', function (Blueprint $table) {
            $table->id();
            // Cascade is safe: configuration rows with no meaning outside their product (loans keep a snapshot).
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('charge_type_id')->constrained('charge_types')->restrictOnDelete();
            $table->string('calculation', 30)->comment('App\Enums\ChargeCalculation');
            $table->decimal('value', 19, 4)->comment('LKR for fixed / per-gram; percent for percentage calculations');
            $table->decimal('min_amount', 19, 4)->nullable()->comment('LKR floor of a percentage charge');
            $table->decimal('max_amount', 19, 4)->nullable()->comment('LKR cap of a percentage charge');
            $table->string('collection', 30)->comment('App\Enums\ChargeCollection');
            $table->string('recurrence', 30)->default('once')->comment('App\Enums\ChargeRecurrence');
            // Islamic late charges are given to charity and never booked as income.
            $table->boolean('is_charity')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'charge_type_id']);
        });

        CheckConstraint::add('product_charges', [
            'product_charges_value_check' => 'value >= 0',
            'product_charges_band_check' => '(min_amount IS NULL OR min_amount >= 0) AND (max_amount IS NULL OR min_amount IS NULL OR min_amount <= max_amount)',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_charges');
    }
};
