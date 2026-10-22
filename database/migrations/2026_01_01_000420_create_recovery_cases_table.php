<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One recovery case per episode of serious arrears (decision D-3):
 *   recovery.notice_dpd       → opened at stage "notice" (notice to borrower and guarantors)
 *   recovery.assign_agent_dpd → stage "internal": an agent is assigned (staff or agency login)
 *   later                     → "external" / "legal" by decision of the recovery manager
 * Resolved when the arrears are cleared, closed when the loan closes. Calls, visits and promises to
 * pay are loan_activities rows with recovery_case_id, so the loan keeps one timeline.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('recovery_cases', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('case_no', 20)->nullable()->unique()->comment('REC-000123; set right after the insert');
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('stage', 20)->comment('App\Enums\RecoveryStage');
            $table->string('status', 20)->default('open')->comment('App\Enums\RecoveryStatus');
            $table->foreignId('assigned_user_id')->nullable()->comment('Staff agent or agency login')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->unsignedSmallInteger('opened_dpd');
            $table->decimal('opened_arrears', 19, 4)->comment('LKR arrears when the case opened');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('outcome', 255)->nullable();
            // Equals loan_id while the case is open: at most one open case per loan, even under parallel runs.
            $table->unsignedBigInteger('open_case_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'stage']);
            $table->index(['assigned_user_id', 'status']);
            $table->index('loan_id');
        });

        CheckConstraint::add('recovery_cases', [
            'recovery_cases_arrears_check' => 'opened_arrears >= 0',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('recovery_cases');
    }
};
