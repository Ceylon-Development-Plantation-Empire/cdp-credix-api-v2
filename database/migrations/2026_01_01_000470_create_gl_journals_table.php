<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Double-entry journals. The posting rules write them inside the same database transaction as the
 * loan movement they record, so the GL can never disagree with the loan sub-ledger. Manual
 * journals go through maker-checker. A posted journal is never changed; a mistake is corrected
 * with another journal.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('gl_journals', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('journal_no', 20)->nullable()->unique()->comment('JV-000123; set right after the insert');
            $table->string('source', 30)->comment('App\Enums\GlJournalSource');
            // What the journal records, e.g. "txn:15" or "due:240". Unique, so nothing is posted twice.
            $table->string('source_key', 80)->nullable()->unique();
            $table->date('entry_date')->comment('Accounting date; never inside a closed period');
            $table->date('value_date')->comment('Date of the underlying movement');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained('loans')->restrictOnDelete();
            $table->foreignId('loan_transaction_id')->nullable()->constrained('loan_transactions')->restrictOnDelete();
            $table->string('narration');
            $table->decimal('total', 19, 4)->comment('LKR sum of the debits (= sum of the credits)');
            $table->string('status', 20)->comment('App\Enums\GlJournalStatus');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'entry_date']);
            $table->index(['loan_id', 'entry_date']);
            $table->index(['branch_id', 'entry_date']);
        });

        CheckConstraint::add('gl_journals', [
            'gl_journals_total_check' => 'total > 0',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('gl_journals');
    }
};
