<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One recovery case per episode of serious arrears (decision D-3):
     *   90 DPD  → case opened at stage "notice" (recovery notice to borrower and guarantors)
     *   180 DPD → stage "internal": a recovery agent must be assigned (staff, or an external
     *             agency login with user_type = agency)
     *   later   → "external" / "legal" by decision of the recovery manager
     * The case is resolved when the arrears are cleared and closed when the loan closes.
     *
     * Calls, visits and promises to pay are written to loan_activities with recovery_case_id, so the
     * loan keeps one timeline.
     */
    public function up(): void
    {
        Schema::create('recovery_cases', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('case_no', 20)->nullable()->unique(); // REC-000123, set right after insert
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('stage', 20);                        // App\Enums\RecoveryStage
            $table->string('status', 20)->default('open');      // App\Enums\RecoveryStatus
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->unsignedSmallInteger('opened_dpd');
            $table->decimal('opened_arrears', 15, 2);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('outcome', 255)->nullable();
            // Equals loan_id while the case is open: at most one open case per loan, even under parallel runs.
            $table->unsignedBigInteger('open_case_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'stage']);
            $table->index(['assigned_user_id', 'status']);
        });

        Schema::table('loan_activities', function (Blueprint $table) {
            $table->foreignId('recovery_case_id')->nullable()->after('loan_id')->constrained('recovery_cases')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recovery_case_id');
        });

        Schema::dropIfExists('recovery_cases');
    }
};
