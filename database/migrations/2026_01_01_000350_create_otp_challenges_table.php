<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes for every purpose: KYC confirmation, loan acceptance, guarantor consent and
 * (when switched on) staff login.
 *
 * SECURITY: the code itself is never stored. code_hash is an HMAC-SHA256 keyed with a server
 * secret, so a copy of this table cannot be used to try the million possible codes offline.
 * payload is the exact snapshot the person confirmed (the evidence); payload_hash detects that the
 * data changed after the code was sent.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('purpose', 30)->comment('App\Enums\OtpPurpose');
            $table->string('subject_type', 60)->comment('Morph alias: customer, loan_party, user');
            $table->unsignedBigInteger('subject_id');
            $table->string('destination', 20)->comment('E.164 phone the code was sent to');
            $table->char('code_hash', 64);
            $table->json('payload')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0)->comment('Wrong codes so far (kept across resends)');
            $table->unsignedTinyInteger('max_attempts');
            $table->unsignedTinyInteger('send_count')->default(1);
            $table->string('status', 20)->default('pending')->comment('App\Enums\OtpStatus');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            // Null for staff-login challenges (nobody is signed in yet).
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'purpose', 'status'], 'otp_challenges_subject_index');
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};
