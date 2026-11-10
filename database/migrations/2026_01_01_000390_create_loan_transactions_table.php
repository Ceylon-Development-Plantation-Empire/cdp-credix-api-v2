<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The loan sub-ledger: disbursements, repayments, charges, penalties, waivers, settlements,
 * write-offs, recoveries and reversals.
 *
 * Immutable: rows are only inserted (no updated_at; App\Models\LoanTransaction refuses updates and
 * deletes; in production the database user should have no UPDATE/DELETE grant here). A mistake is
 * corrected with a reversal row pointing at the original (reverses_transaction_id is unique, so a
 * transaction is reversed at most once). Every row is posted to the double-entry general ledger
 * in the same database transaction.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('loan_transactions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('txn_no', 20)->nullable()->unique()->comment('TXN-000123; set right after the insert');
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->string('type', 30)->comment('App\Enums\TransactionType');
            $table->foreignId('charge_type_id')->nullable()->comment('Which fee a charge / settlement fee row is')
                ->constrained('charge_types')->restrictOnDelete();
            $table->decimal('amount', 19, 4)->comment('LKR, always positive; the type gives the direction');
            // DECISION (denormalised on purpose): what the borrower owed on the loan right after this row
            // (principal + profit + charges + penalty), so a statement needs no running sum.
            $table->decimal('balance_after', 19, 4)->comment('LKR outstanding after this transaction');
            $table->date('value_date')->comment('Business date the money moved');
            $table->string('channel', 20)->comment('App\Enums\PaymentChannel');
            $table->string('external_reference', 60)->nullable()->comment('Bank reference / cheque number');
            $table->foreignId('payer_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            // SECURITY: from the Idempotency-Key header (hashed with the loan); a retry never posts twice.
            $table->char('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('reverses_transaction_id')->nullable()->unique()->constrained('loan_transactions')->restrictOnDelete();
            $table->string('narration')->nullable();
            $table->boolean('is_charity')->default(false)->comment('Islamic late charge: credited to charity, never income');
            $table->foreignId('posted_by')->nullable()->comment('Null = posted by a system job')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->comment('Checker of a maker-checker posting')->constrained('users')->restrictOnDelete();
            $table->string('ip_address', 45)->nullable()->comment('Source address of the request; null for system jobs');
            $table->timestamp('created_at')->nullable();

            // Statements and reports: a loan's rows by date, totals by type and date, charge counts.
            $table->index(['loan_id', 'value_date']);
            $table->index(['type', 'value_date']);
            $table->index(['loan_id', 'charge_type_id']);
        });

        CheckConstraint::add('loan_transactions', [
            'loan_transactions_amount_check' => 'amount > 0 AND balance_after >= 0',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_transactions');
    }
};
