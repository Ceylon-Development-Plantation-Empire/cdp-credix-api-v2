<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Where each rupee of a ledger transaction went: which installment and which part of it
     * (principal, interest/profit, charge, penalty). The lines of a transaction always add up to
     * its amount (checked by the service before anything is saved).
     *
     * A reversal writes the same lines with negative amounts, so the original rows are never
     * touched and SUM(amount) per installment is always the true paid amount.
     */
    public function up(): void
    {
        Schema::create('loan_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_transaction_id')->constrained('loan_transactions')->restrictOnDelete();
            $table->foreignId('loan_installment_id')->nullable()->constrained('loan_installments')->restrictOnDelete();
            $table->string('component', 20); // App\Enums\AllocationComponent
            $table->decimal('amount', 15, 2);  // negative on a reversal
            $table->timestamp('created_at')->nullable();

            $table->index(['loan_installment_id', 'component']);
        });

        Schema::table('loan_transactions', function (Blueprint $table) {
            // Islamic late charges are credited to a charity payable, never to income.
            $table->boolean('is_charity')->default(false)->after('narration');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('outstanding_penalty', 15, 2)->default(0)->after('outstanding_charges');
        });

        Schema::table('approval_requests', function (Blueprint $table) {
            // Branch of the subject (a loan or payment), so branch staff only see their branch's
            // requests. Null for head-office subjects such as products.
            $table->foreignId('branch_id')->nullable()->after('subject_id')->constrained('branches')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('outstanding_penalty');
        });

        Schema::table('loan_transactions', function (Blueprint $table) {
            $table->dropColumn('is_charity');
        });

        Schema::dropIfExists('loan_allocations');
    }
};
