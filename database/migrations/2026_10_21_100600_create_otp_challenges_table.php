<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One OTP table for every purpose (KYC confirmation, loan acceptance, guarantor consent,
     * customer login, password reset). The old system had four.
     *
     * The code itself is never stored: code_hash is an HMAC-SHA256 keyed with a server secret,
     * so even someone with a copy of this table cannot try the 1,000,000 possible codes offline.
     * payload is the exact snapshot the customer confirmed (the evidence), payload_hash lets us
     * detect that the data changed after the OTP was sent.
     */
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('purpose', 30);        // App\Enums\OtpPurpose
            $table->morphs('subject');
            $table->string('destination', 20);    // phone the code was sent to (E.164)
            $table->char('code_hash', 64);
            $table->json('payload')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts');
            $table->unsignedTinyInteger('send_count')->default(1);
            $table->string('status', 20)->default('pending'); // App\Enums\OtpStatus
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'purpose', 'status'], 'otp_challenges_subject_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};
