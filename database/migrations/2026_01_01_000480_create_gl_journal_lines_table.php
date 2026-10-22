<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The debit and credit lines of a journal. The lines of one journal always balance (debits =
 * credits = journal total), checked by GeneralLedgerService before anything is saved and proven
 * by the reconciliation command. Immutable like the journal.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('gl_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gl_journal_id')->constrained('gl_journals')->restrictOnDelete();
            $table->foreignId('gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->decimal('debit', 19, 4)->default(0)->comment('LKR');
            $table->decimal('credit', 19, 4)->default(0)->comment('LKR');
            $table->string('memo')->nullable();
            $table->timestamp('created_at')->nullable();

            // Account statements and the trial balance read lines per account.
            $table->index(['gl_account_id', 'gl_journal_id']);
            $table->index('gl_journal_id');
        });

        // A line is a debit or a credit, never both and never negative.
        CheckConstraint::add('gl_journal_lines', [
            'gl_journal_lines_side_check' => 'debit >= 0 AND credit >= 0 AND (debit = 0) <> (credit = 0)',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('gl_journal_lines');
    }
};
