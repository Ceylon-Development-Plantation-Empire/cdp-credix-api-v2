<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which collateral types a product accepts and how much can be lent against each (loan-to-value).
 * The LTV is copied onto each pledged item when it is added, so later rule changes never change
 * what was agreed.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('product_collateral_rules', function (Blueprint $table) {
            $table->id();
            // Cascade is safe: product configuration only (pledged items keep their own LTV copy).
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('collateral_type', 30)->comment('App\Enums\CollateralType');
            $table->decimal('max_ltv_percent', 5, 2)->comment('Percent of the market value that can be lent');
            $table->boolean('is_mandatory')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'collateral_type']);
        });

        CheckConstraint::add('product_collateral_rules', [
            'product_collateral_rules_ltv_check' => 'max_ltv_percent > 0 AND max_ltv_percent <= 100',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_collateral_rules');
    }
};
