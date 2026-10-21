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
     * One row per loan from application to closure (the old system split this over applications,
     * loan_applications and group_loans).
     *
     * A group loan is a parent "facility" row (customer_id null) with one child loan per member
     * (parent_id). Each member's loan has its own schedule, arrears and credit history, while
     * the workflow is done once on the parent and moves every child in the same transaction.
     *
     * Terms are copied from the product when the application is submitted and fixed again at
     * approval, so a later product change never changes a running loan. Who reviewed, verified
     * and approved is in loan_activities, not in columns here.
     */
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('application_no', 20)->nullable()->unique(); // APP-000123, set right after insert
            $table->string('loan_no', 20)->nullable()->unique();        // LN-000123, set at disbursement
            $table->foreignId('parent_id')->nullable()->constrained('loans')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('borrower_type', 20);                        // App\Enums\BorrowerType
            $table->foreignId('borrower_group_id')->nullable()->constrained('borrower_groups')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete(); // null on a group parent
            $table->foreignId('assigned_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('purpose')->nullable();

            /* Terms (snapshot) */
            $table->string('finance_basis', 20);
            $table->string('pricing_method', 30);
            $table->string('repayment_frequency', 20);
            $table->decimal('rate', 9, 4);                              // % per annum
            $table->unsignedSmallInteger('tenure');                     // number of installments
            $table->decimal('requested_amount', 15, 2);
            $table->decimal('approved_amount', 15, 2)->nullable();
            $table->decimal('financed_amount', 15, 2)->nullable();      // principal / Murabaha cost price / lease capital
            $table->decimal('profit_amount', 15, 2)->nullable();        // interest (conventional) or profit (Islamic)
            $table->decimal('upfront_charges', 15, 2)->default(0);
            $table->decimal('net_disbursement_amount', 15, 2)->nullable();
            $table->decimal('installment_amount', 15, 2)->nullable();
            $table->decimal('residual_value', 15, 2)->default(0);       // leases only
            $table->json('terms_snapshot')->nullable();                 // product + charges at submit/approval (audit only)

            /* Assessment */
            $table->decimal('assessed_monthly_income', 15, 2)->nullable();
            $table->decimal('assessed_monthly_expenses', 15, 2)->nullable();
            $table->decimal('existing_obligations', 15, 2)->nullable();
            $table->decimal('dsr_percent', 7, 2)->nullable();

            /* Dates */
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->date('first_due_date')->nullable();
            $table->date('maturity_date')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->string('status', 20)->default('draft');            // App\Enums\LoanStatus
            $table->string('closure_reason', 20)->nullable();           // App\Enums\ClosureReason

            /* Servicing cache, rebuilt from the schedule and ledger (phase 6) */
            $table->decimal('outstanding_principal', 15, 2)->default(0);
            $table->decimal('outstanding_profit', 15, 2)->default(0);
            $table->decimal('outstanding_charges', 15, 2)->default(0);
            $table->decimal('arrears_amount', 15, 2)->default(0);
            $table->unsignedSmallInteger('days_past_due')->default(0);
            $table->unsignedTinyInteger('risk_stage')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index(['borrower_group_id', 'status']);
            $table->index(['status', 'days_past_due']);
        });

        // Money can never go negative and an approval can never exceed the request (MySQL 8 enforces CHECK).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE loans
                ADD CONSTRAINT loans_amounts_check CHECK (
                    requested_amount > 0
                    AND (approved_amount IS NULL OR (approved_amount > 0 AND approved_amount <= requested_amount))
                    AND rate >= 0 AND tenure >= 1
                    AND upfront_charges >= 0 AND outstanding_principal >= 0 AND outstanding_profit >= 0
                    AND outstanding_charges >= 0 AND arrears_amount >= 0
                )');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
