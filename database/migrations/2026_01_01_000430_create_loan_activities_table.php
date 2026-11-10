<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One timeline per loan: every status change (who reviewed, verified, approved, disbursed), notes,
 * and recovery calls, visits and promises to pay. The maker-checker rules read this table to know
 * who did which step, so it is written in the same transaction as the status change and never
 * edited (App\Models\LoanActivity refuses updates and deletes).
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('loan_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->foreignId('recovery_case_id')->nullable()->constrained('recovery_cases')->restrictOnDelete();
            $table->string('type', 30)->comment('App\Enums\LoanActivityType');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            // TEXT: officers' notes and visit reports can be long.
            $table->text('remarks')->nullable();
            $table->decimal('promised_amount', 19, 4)->nullable()->comment('LKR promise to pay');
            $table->date('promised_date')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('performed_by')->nullable()->comment('Null = system job')->constrained('users')->restrictOnDelete();
            $table->timestamp('performed_at');

            $table->index(['loan_id', 'performed_at']);
            $table->index(['loan_id', 'to_status']);
            $table->index('recovery_case_id');
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_activities');
    }
};
