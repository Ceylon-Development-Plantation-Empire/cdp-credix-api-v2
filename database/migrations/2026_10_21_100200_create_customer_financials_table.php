<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One table for everything the credit officer looks at to judge affordability: income,
     * expenses, assets and existing liabilities. The old system had a table for each (and
     * income columns on the customer); they all share the same shape.
     *
     * amount means: monthly amount for income/expense, value for an asset, outstanding balance
     * for a liability. monthly_obligation is the installment of an existing loan (used in DSR).
     */
    public function up(): void
    {
        Schema::create('customer_financials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('kind', 20);        // App\Enums\FinancialKind
            $table->string('category', 40);
            $table->string('description')->nullable();
            $table->string('institution_name', 150)->nullable();
            $table->string('reference_no', 60)->nullable();
            $table->decimal('amount', 15, 2);
            $table->decimal('monthly_obligation', 15, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_financials');
    }
};
