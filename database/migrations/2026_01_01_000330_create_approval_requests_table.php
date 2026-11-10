<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One maker-checker table for every sensitive change (Shariah approval, payment reversal,
 * penalty waiver, restructure, manual journal, write-off …). The payload holds the exact change
 * proposed, so the checker approves what they saw and the history shows what was asked, by whom
 * and why. The requester can never decide their own request (ApprovalRequestService).
 *
 * DECISION: polymorphic subject (loan, payment, product, journal), with a morph map.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('subject_type', 60)->comment('Morph alias: loan, loan_transaction, product, gl_journal');
            $table->unsignedBigInteger('subject_id');
            // Branch of the subject, so branch staff only see their own requests. Null = head-office subject (products).
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('action', 40)->comment('App\Enums\ApprovalAction');
            $table->json('payload')->nullable()->comment('The exact change proposed');
            $table->string('reason', 1000);
            $table->string('status', 20)->default('pending')->comment('App\Enums\ApprovalStatus');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reference', 100)->nullable()->comment('Board resolution number (Shariah decisions)');
            $table->string('decision_remarks', 1000)->nullable();
            $table->timestamps();

            // "Is anything pending for this record?" and the inbox per branch.
            $table->index(['subject_type', 'subject_id', 'status']);
            $table->index(['status', 'branch_id', 'created_at']);
            $table->index('action');
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
