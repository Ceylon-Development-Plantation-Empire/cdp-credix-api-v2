<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One timeline per loan: every status change (who reviewed, verified, approved, disbursed),
     * notes, and later recovery calls, visits and promises to pay. The maker-checker rules read
     * this table to know who did which step, so it is written in the same transaction as the
     * status change and never edited.
     *
     * recovery_case_id is added in phase 7 together with recovery_cases.
     */
    public function up(): void
    {
        Schema::create('loan_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->string('type', 30);                     // App\Enums\LoanActivityType
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('remarks')->nullable();
            $table->decimal('promised_amount', 15, 2)->nullable();
            $table->date('promised_date')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('performed_at');

            $table->index(['loan_id', 'performed_at']);
            $table->index(['loan_id', 'to_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_activities');
    }
};
