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
     * The general ledger (phase 8): a chart of accounts and double-entry journals.
     *
     * Journals are written by the posting rules inside the same database transaction as the loan
     * movement they record (disbursement, payment, late charge …), so the GL can never disagree
     * with the loan sub-ledger. Manual journals go through maker-checker. Posted journals are never
     * changed; a mistake is corrected with another journal.
     *
     * The system accounts the posting rules need are inserted here, not in a seeder, so they exist
     * in every environment. Accountants may rename or renumber them; the slug is what the code uses.
     */
    public function up(): void
    {
        Schema::create('gl_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->string('type', 20);                     // App\Enums\GlAccountType
            $table->string('slug', 60)->nullable()->unique(); // set on system accounts only
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('gl_journals', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('journal_no', 20)->nullable()->unique(); // JV-000123, set right after insert
            $table->string('source', 30);                           // App\Enums\GlJournalSource
            // What the journal records, e.g. "txn:15" or "due:240". Unique, so nothing is posted twice.
            $table->string('source_key', 80)->nullable()->unique();
            $table->date('entry_date');                             // accounting date (never inside a closed period)
            $table->date('value_date');                             // date of the underlying movement
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained('loans')->restrictOnDelete();
            $table->foreignId('loan_transaction_id')->nullable()->constrained('loan_transactions')->restrictOnDelete();
            $table->string('narration');
            $table->decimal('total', 15, 2);
            $table->string('status', 20);                           // App\Enums\GlJournalStatus
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'entry_date']);
            $table->index(['loan_id', 'entry_date']);
        });

        Schema::create('gl_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gl_journal_id')->constrained('gl_journals')->restrictOnDelete();
            $table->foreignId('gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('memo')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['gl_account_id', 'gl_journal_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            // A line is a debit or a credit, never both and never negative.
            DB::statement('ALTER TABLE gl_journal_lines ADD CONSTRAINT gl_journal_lines_side_check CHECK (debit >= 0 AND credit >= 0 AND (debit = 0) <> (credit = 0))');
            DB::statement('ALTER TABLE gl_journals ADD CONSTRAINT gl_journals_total_check CHECK (total > 0)');
        }

        $now = now();

        DB::table('gl_accounts')->insert(array_map(fn (array $a) => [
            'code' => $a[0],
            'slug' => $a[1],
            'type' => $a[2],
            'name' => $a[3],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['1000', 'cash-in-hand', 'asset', 'Cash in hand'],
            ['1010', 'bank', 'asset', 'Bank'],
            ['1100', 'loans-receivable', 'asset', 'Loans receivable (principal)'],
            ['1110', 'islamic-financing-receivable', 'asset', 'Islamic financing receivable (principal / cost)'],
            ['1200', 'interest-receivable', 'asset', 'Interest receivable'],
            ['1210', 'profit-receivable', 'asset', 'Islamic profit receivable'],
            ['1300', 'charges-receivable', 'asset', 'Fees and charges receivable'],
            ['1310', 'penalty-receivable', 'asset', 'Late charges receivable'],
            ['2100', 'charity-payable', 'liability', 'Charity payable (Islamic late charges)'],
            ['2200', 'customer-advances', 'liability', 'Customer advances'],
            ['4100', 'interest-income', 'income', 'Interest income'],
            ['4110', 'profit-income', 'income', 'Islamic profit income'],
            ['4200', 'fee-income', 'income', 'Fee and charge income'],
            ['4210', 'penalty-income', 'income', 'Late payment charge income'],
            ['5100', 'loans-written-off', 'expense', 'Loans written off'],
        ]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gl_journal_lines');
        Schema::dropIfExists('gl_journals');
        Schema::dropIfExists('gl_accounts');
    }
};
