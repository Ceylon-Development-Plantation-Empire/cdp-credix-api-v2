<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A sellable loan product. Every business rule that used to be an "if code is X" in the old
     * system is a column here, so a new product never needs a code change.
     *
     * Rates are % per annum. Tenure is a number of installments (for bullet products: months).
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->constrained('product_categories')->restrictOnDelete();
            // The same product in the other basis (e.g. Vehicle Lease ↔ Vehicle Ijarah).
            $table->foreignId('counterpart_product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('pricing_method', 30); // App\Enums\PricingMethod
            $table->decimal('default_rate', 9, 4);
            $table->decimal('min_rate', 9, 4);
            $table->decimal('max_rate', 9, 4);
            $table->decimal('min_amount', 15, 2);
            $table->decimal('max_amount', 15, 2);
            $table->unsignedSmallInteger('min_tenure');
            $table->unsignedSmallInteger('max_tenure');
            $table->string('repayment_frequency', 20); // App\Enums\RepaymentFrequency

            $table->boolean('allows_individual')->default(true);
            $table->boolean('allows_joint')->default(false);
            $table->boolean('allows_group')->default(false);
            $table->unsignedSmallInteger('min_group_members')->nullable();
            $table->unsignedSmallInteger('max_group_members')->nullable();
            $table->unsignedTinyInteger('min_guarantors')->default(0);
            $table->unsignedTinyInteger('max_guarantors')->default(0);
            $table->boolean('requires_collateral')->default(false);

            // Null = use the system setting.
            $table->unsignedSmallInteger('grace_period_days')->nullable();
            $table->decimal('max_dsr_percent', 5, 2)->nullable();
            $table->decimal('residual_value_percent', 5, 2)->nullable();
            $table->boolean('early_settlement_allowed')->default(true);
            $table->decimal('early_settlement_rebate_percent', 5, 2)->nullable();

            $table->string('status', 20)->default('draft')->index(); // App\Enums\ProductStatus
            $table->timestamp('shariah_approved_at')->nullable();
            $table->string('shariah_reference', 100)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'status']);
        });

        // The database refuses impossible bands even if a bug ever skips the request validation.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products
                ADD CONSTRAINT products_rate_band_check CHECK (min_rate >= 0 AND min_rate <= default_rate AND default_rate <= max_rate),
                ADD CONSTRAINT products_amount_band_check CHECK (min_amount > 0 AND min_amount <= max_amount),
                ADD CONSTRAINT products_tenure_band_check CHECK (min_tenure >= 1 AND min_tenure <= max_tenure)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
