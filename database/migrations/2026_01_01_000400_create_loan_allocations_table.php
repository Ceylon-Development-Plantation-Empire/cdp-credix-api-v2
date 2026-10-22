<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each rupee of a ledger transaction went: which installment and which part of it
 * (principal, interest/profit, charge, penalty). The lines of a transaction always add up to its
 * amount (LedgerService refuses anything else). A reversal writes the same lines with negative
 * amounts, so original rows are never touched and SUM(amount) per installment is the true paid
 * amount. Immutable like the transaction.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('loan_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_transaction_id')->constrained('loan_transactions')->restrictOnDelete();
            $table->foreignId('loan_installment_id')->nullable()->constrained('loan_installments')->restrictOnDelete();
            $table->string('component', 20)->comment('App\Enums\AllocationComponent');
            $table->decimal('amount', 19, 4)->comment('LKR; negative only on a reversal');
            $table->timestamp('created_at')->nullable();

            $table->index(['loan_installment_id', 'component']);
            $table->index('loan_transaction_id');
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_allocations');
    }
};
