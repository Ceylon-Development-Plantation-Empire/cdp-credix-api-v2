<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per pledged item, so each gold item, vehicle or deed is valued, verified and released on
 * its own. Type-specific details (deed number, chassis number, karat and weight …) are JSON in
 * details. (The type column becomes collateral_type_id → lookups in 2026_10_22_000100, where
 * collateral types became data; their fields are validated by App\Support\Collateral\CollateralTypes.)
 *
 * ltv_percent is copied from the product's collateral rule when the item is added, so a later
 * change of the rule does not change what was agreed.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('collaterals', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->string('type', 30)->comment('App\Enums\CollateralType');
            $table->foreignId('owner_customer_id')->nullable()->comment('Null = owned by the borrower')->constrained('customers')->restrictOnDelete();
            $table->string('description');
            $table->json('details')->comment('Type-specific fields');
            $table->decimal('market_value', 19, 4)->comment('LKR');
            $table->decimal('forced_sale_value', 19, 4)->nullable()->comment('LKR');
            $table->decimal('ltv_percent', 5, 2)->comment('Copied from the product rule');
            $table->decimal('eligible_amount', 19, 4)->comment('LKR = market value × LTV, rounded half-up to cents');
            $table->date('valued_on');
            $table->string('valued_by', 150)->nullable()->comment('Valuer name / firm');
            $table->string('status', 20)->default('proposed')->comment('App\Enums\CollateralStatus');
            $table->string('registration_ref', 60)->nullable()->comment('Mortgage bond number / CR endorsement');
            $table->string('custody_ref', 60)->nullable()->comment('Gold packet number and vault location');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('perfected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('perfected_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['loan_id', 'status']);
        });

        CheckConstraint::add('collaterals', [
            'collaterals_value_check' => 'market_value > 0 AND (forced_sale_value IS NULL OR forced_sale_value >= 0) AND eligible_amount >= 0',
            'collaterals_ltv_check' => 'ltv_percent > 0 AND ltv_percent <= 100',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('collaterals');
    }
};
