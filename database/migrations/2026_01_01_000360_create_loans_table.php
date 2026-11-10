<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per loan from application to closure.
 *
 * A group loan is a parent "facility" row (customer_id null) with one child loan per member
 * (parent_id). Each member loan has its own schedule, arrears and credit history; the workflow
 * runs once on the parent and moves every child in the same transaction.
 *
 * Terms are copied from the product at submission and fixed again at approval (rate, tenure,
 * pricing method … plus the product and charges in terms_snapshot), so a later product change
 * never changes a running loan. Who reviewed, verified and approved is in loan_activities.
 *
 * DECISION (denormalised on purpose): the outstanding_* / arrears / days_past_due columns are a
 * servicing cache rebuilt from the schedule and ledger after every posting (LoanBalanceService),
 * so lists, dashboards and the nightly run never sum installments row by row. The ledger stays
 * the source of truth; the reconciliation command checks the cache against it.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('application_no', 20)->nullable()->unique()->comment('APP-000123; set right after the insert');
            $table->string('loan_no', 20)->nullable()->unique()->comment('LN-000123; set at disbursement');
            $table->foreignId('parent_id')->nullable()->comment('Group facility of a member loan')->constrained('loans')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('borrower_type', 20)->comment('App\Enums\BorrowerType: individual, joint, group');
            $table->foreignId('borrower_group_id')->nullable()->constrained('borrower_groups')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->comment('Main borrower; null on a group facility')->constrained('customers')->restrictOnDelete();
            $table->foreignId('assigned_officer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('purpose', 1000)->nullable();
            $table->char('currency', 3)->default('LKR')->comment('ISO 4217, copied from the product');

            /* Terms (snapshot) */
            $table->string('finance_basis', 20)->comment('App\Enums\FinanceBasis');
            $table->string('pricing_method', 30)->comment('App\Enums\PricingMethod');
            $table->string('repayment_frequency', 20)->comment('App\Enums\RepaymentFrequency');
            $table->decimal('rate', 9, 4)->comment('% p.a. agreed');
            $table->unsignedSmallInteger('tenure')->comment('Number of installments (bullet: months)');
            $table->decimal('requested_amount', 19, 4)->comment('LKR');
            $table->decimal('approved_amount', 19, 4)->nullable()->comment('LKR; null until approved');
            $table->decimal('financed_amount', 19, 4)->nullable()->comment('LKR principal / Murabaha cost / lease capital');
            $table->decimal('profit_amount', 19, 4)->nullable()->comment('LKR interest (conventional) or profit (Islamic) over the term');
            $table->decimal('upfront_charges', 19, 4)->default(0)->comment('LKR fees deducted at disbursement');
            $table->decimal('net_disbursement_amount', 19, 4)->nullable()->comment('LKR actually paid out');
            $table->decimal('installment_amount', 19, 4)->nullable()->comment('LKR regular installment');
            $table->decimal('residual_value', 19, 4)->default(0)->comment('LKR balloon (leases only)');
            $table->json('terms_snapshot')->nullable()->comment('Product + charges at submission / approval (audit)');

            /* Assessment (copied at decision time) */
            $table->decimal('assessed_monthly_income', 19, 4)->nullable();
            $table->decimal('assessed_monthly_expenses', 19, 4)->nullable();
            $table->decimal('existing_obligations', 19, 4)->nullable();
            $table->decimal('dsr_percent', 7, 2)->nullable()->comment('Debt service ratio at assessment (%)');

            /* Dates */
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->date('first_due_date')->nullable();
            $table->date('maturity_date')->nullable();
            $table->unsignedSmallInteger('renewal_count')->default(0)->comment('Gold loan renewals so far');
            $table->date('schedule_starts_on')->nullable()->comment('Start of the schedule in force (disbursement, renewal or restructure)');
            $table->timestamp('closed_at')->nullable();
            $table->string('status', 20)->default('draft')->comment('App\Enums\LoanStatus');
            $table->string('closure_reason', 20)->nullable()->comment('App\Enums\ClosureReason');

            /* Servicing cache (see the class comment) */
            $table->decimal('outstanding_principal', 19, 4)->default(0);
            $table->decimal('outstanding_profit', 19, 4)->default(0);
            $table->decimal('outstanding_charges', 19, 4)->default(0);
            $table->decimal('outstanding_penalty', 19, 4)->default(0);
            $table->decimal('arrears_amount', 19, 4)->default(0)->comment('LKR overdue (past grace)');
            $table->unsignedSmallInteger('days_past_due')->default(0);
            $table->unsignedTinyInteger('risk_stage')->default(1)->comment('SLFRS 9 stage 1-3');
            $table->date('income_suspended_at')->nullable()->comment('Interest moved to suspense from this date (non-performing)');

            $table->json('meta')->nullable()->comment('Custom attributes (lookups.type = custom_field.loan)');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // Hot paths: branch lists by status, a customer's loans, a group's loans, the nightly arrears run.
            $table->index(['branch_id', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index(['borrower_group_id', 'status']);
            $table->index(['status', 'days_past_due']);
            $table->index(['status', 'maturity_date']);
        });

        // Money can never go negative and an approval can never exceed the request.
        CheckConstraint::add('loans', [
            'loans_amounts_check' => 'requested_amount > 0'
                .' AND (approved_amount IS NULL OR (approved_amount > 0 AND approved_amount <= requested_amount))'
                .' AND rate >= 0 AND tenure >= 1 AND upfront_charges >= 0 AND residual_value >= 0'
                .' AND (net_disbursement_amount IS NULL OR net_disbursement_amount >= 0)',
            'loans_outstanding_check' => 'outstanding_principal >= 0 AND outstanding_profit >= 0 AND outstanding_charges >= 0'
                .' AND outstanding_penalty >= 0 AND arrears_amount >= 0',
            'loans_dates_check' => 'maturity_date IS NULL OR first_due_date IS NULL OR maturity_date >= first_due_date',
            'loans_risk_stage_check' => 'risk_stage BETWEEN 1 AND 3',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
