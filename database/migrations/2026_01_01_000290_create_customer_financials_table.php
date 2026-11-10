<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the credit officer weighs for affordability: income, expenses, assets, liabilities.
 *
 * amount means: monthly amount for income / expense, value for an asset, outstanding balance for
 * a liability. monthly_obligation is the installment of an existing loan (used in the DSR). The
 * figures used for a decision are copied onto the loan (assessed_*), so editing a row later never
 * changes a decided loan.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('customer_financials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('kind', 20)->comment('App\Enums\FinancialKind: income, expense, asset, liability');
            $table->foreignId('category_id')->comment('lookups.id where type = financial_category.<kind>')
                ->constrained('lookups')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->string('institution_name', 150)->nullable()->comment('Lender of a liability / employer of an income');
            $table->string('reference_no', 60)->nullable();
            $table->decimal('amount', 19, 4)->comment('LKR; see the table comment for its meaning per kind');
            $table->decimal('monthly_obligation', 19, 4)->nullable()->comment('LKR installment of an existing liability');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'kind']);
        });

        CheckConstraint::add('customer_financials', [
            'customer_financials_amount_check' => 'amount >= 0 AND (monthly_obligation IS NULL OR monthly_obligation >= 0)',
            'customer_financials_period_check' => 'end_date IS NULL OR start_date IS NULL OR end_date >= start_date',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_financials');
    }
};
