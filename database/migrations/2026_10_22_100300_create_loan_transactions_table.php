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
     * The loan sub-ledger. Rows are only ever inserted: there is no updated_at and the model
     * refuses updates and deletes. A mistake is corrected with a reversal transaction that points
     * at the original (reverses_transaction_id is unique, so a transaction is reversed once).
     *
     * Phase 5 writes the disbursement; repayments, charges, penalties and reversals follow in
     * phase 6 together with loan_allocations.
     */
    public function up(): void
    {
        Schema::create('loan_transactions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('txn_no', 20)->nullable()->unique();       // TXN-000123, set right after insert
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->string('type', 30);                               // App\Enums\TransactionType
            $table->decimal('amount', 15, 2);
            $table->date('value_date');
            $table->string('channel', 20);                            // App\Enums\PaymentChannel
            $table->string('external_reference', 60)->nullable();     // bank reference / cheque number
            $table->foreignId('payer_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            // From the Idempotency-Key header: a double click or a network retry never posts twice.
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('reverses_transaction_id')->nullable()->unique()->constrained('loan_transactions')->restrictOnDelete();
            $table->string('narration')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['loan_id', 'value_date']);
            $table->index(['type', 'value_date']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE loan_transactions ADD CONSTRAINT loan_transactions_amount_check CHECK (amount > 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_transactions');
    }
};
