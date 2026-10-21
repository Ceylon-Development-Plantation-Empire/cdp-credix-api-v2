<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per pledged item, so each gold item, vehicle or deed is valued, verified and
     * released on its own. Type-specific details (deed number, chassis number, karat and weight …)
     * are in details and validated by App\Enums\CollateralType::detailRules().
     *
     * ltv_percent is copied from the product's collateral rule when the item is added, so a later
     * change of the rule does not change what was agreed.
     */
    public function up(): void
    {
        Schema::create('collaterals', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->string('type', 30);                         // App\Enums\CollateralType
            $table->foreignId('owner_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('description');
            $table->json('details');
            $table->decimal('market_value', 15, 2);
            $table->decimal('forced_sale_value', 15, 2)->nullable();
            $table->decimal('ltv_percent', 5, 2);
            $table->decimal('eligible_amount', 15, 2);          // value × LTV
            $table->date('valued_on');
            $table->string('valued_by', 150)->nullable();       // valuer name / firm
            $table->string('status', 20)->default('proposed');  // App\Enums\CollateralStatus
            $table->string('registration_ref', 60)->nullable(); // mortgage bond number / CR endorsement
            $table->string('custody_ref', 60)->nullable();      // gold packet number and vault location
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('perfected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('perfected_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['loan_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collaterals');
    }
};
