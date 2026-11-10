<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sellable loan products. Every business rule that used to be an "if code is X" is a column here,
 * so a new product is data, never a code change.
 *
 * Units: money in LKR (DECIMAL(19,4), stored rounded to cents); rates are percent per annum
 * (DECIMAL(9,4): rates are quoted to at most 4 decimals, e.g. 18.2500 %; periodic rates are
 * derived in memory at 12-digit precision and never stored); tenure is a number of installments
 * (bullet products: months).
 *
 * Soft deletes: a product is retired, never removed (loans keep pointing at it).
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 150);
            // Matches the request limit (ProductFieldRules: max 2000).
            $table->string('description', 2000)->nullable();
            $table->foreignId('category_id')->constrained('product_categories')->restrictOnDelete();
            // The same product in the other basis (Vehicle Lease ↔ Vehicle Ijarah).
            $table->foreignId('counterpart_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->char('currency', 3)->default('LKR')->comment('ISO 4217; the system is single-currency today');

            /* Pricing */
            $table->string('pricing_method', 30)->comment('App\Enums\PricingMethod (strategy class per method)');
            $table->decimal('default_rate', 9, 4)->comment('% p.a.');
            $table->decimal('min_rate', 9, 4)->comment('% p.a.');
            $table->decimal('max_rate', 9, 4)->comment('% p.a.');

            /* Limits */
            $table->decimal('min_amount', 19, 4)->comment('LKR');
            $table->decimal('max_amount', 19, 4)->comment('LKR');
            $table->unsignedSmallInteger('min_tenure')->comment('Installments (bullet: months)');
            $table->unsignedSmallInteger('max_tenure')->comment('Installments (bullet: months)');
            $table->string('repayment_frequency', 20)->comment('App\Enums\RepaymentFrequency');

            /* Who can borrow */
            $table->boolean('allows_individual')->default(true);
            $table->boolean('allows_joint')->default(false);
            $table->boolean('allows_group')->default(false);
            $table->unsignedSmallInteger('min_group_members')->nullable()->comment('Group products only');
            $table->unsignedSmallInteger('max_group_members')->nullable()->comment('Group products only');
            $table->unsignedTinyInteger('min_guarantors')->default(0);
            $table->unsignedTinyInteger('max_guarantors')->default(0);
            $table->boolean('requires_collateral')->default(false);

            /* Servicing rules (null = use the system setting) */
            $table->unsignedSmallInteger('grace_period_days')->nullable()->comment('Null = setting repayment.grace_period_days');
            $table->decimal('max_dsr_percent', 5, 2)->nullable()->comment('Null = setting affordability.max_dsr_percent');
            $table->decimal('residual_value_percent', 5, 2)->nullable()->comment('Leases / Ijarah only');
            $table->boolean('early_settlement_allowed')->default(true);
            $table->decimal('early_settlement_rebate_percent', 5, 2)->nullable();

            /* Life cycle */
            $table->string('status', 20)->default('draft')->index()->comment('App\Enums\ProductStatus');
            $table->timestamp('shariah_approved_at')->nullable();
            $table->string('shariah_reference', 100)->nullable()->comment('Shariah board resolution number');
            $table->json('meta')->nullable()->comment('Custom product attributes (lookups.type = custom_field.product)');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'status']);
        });

        // The database refuses impossible bands even if a bug ever skips the request validation.
        CheckConstraint::add('products', [
            'products_rate_band_check' => 'min_rate >= 0 AND min_rate <= default_rate AND default_rate <= max_rate',
            'products_amount_band_check' => 'min_amount > 0 AND min_amount <= max_amount',
            'products_tenure_band_check' => 'min_tenure >= 1 AND min_tenure <= max_tenure',
            'products_guarantor_band_check' => 'min_guarantors <= max_guarantors',
            'products_group_band_check' => 'min_group_members IS NULL OR max_group_members IS NULL OR min_group_members <= max_group_members',
            'products_percent_check' => '(max_dsr_percent IS NULL OR max_dsr_percent BETWEEN 0 AND 100)'
                .' AND (residual_value_percent IS NULL OR residual_value_percent BETWEEN 0 AND 100)'
                .' AND (early_settlement_rebate_percent IS NULL OR early_settlement_rebate_percent BETWEEN 0 AND 100)',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
