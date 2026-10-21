<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One maker-checker table for every sensitive change (Shariah approval, payment reversal,
     * penalty waiver, write-off …). The payload holds the exact change that was proposed, so the
     * checker approves what they saw and the history shows what was asked, by whom and why.
     */
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->morphs('subject');
            $table->string('action', 40)->index();  // App\Enums\ApprovalAction
            $table->json('payload')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('pending')->index(); // App\Enums\ApprovalStatus
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reference', 100)->nullable();
            $table->text('decision_remarks')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
