<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer review link: each borrower of a loan gets one SMS link where they read their
 * details and the loan terms, ask for corrections and confirm once with an SMS code.
 *
 * DECISION: kept on the party row (one link per person per loan) instead of a new table. A new
 * link replaces the old one, and what happened (sent, corrections asked, confirmed) is written to
 * the loan timeline, which is the history. Only a hash of the token is stored, so a copy of the
 * database cannot be used to open anyone's link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_parties', function (Blueprint $table) {
            $table->char('review_token_hash', 64)->nullable()->unique()->after('consent_hash')->comment('SHA-256 of the link token; the token itself is never stored');
            $table->timestamp('review_sent_at')->nullable()->after('review_token_hash');
            $table->timestamp('review_expires_at')->nullable()->after('review_sent_at');
            $table->timestamp('review_viewed_at')->nullable()->after('review_expires_at');
            $table->timestamp('review_confirmed_at')->nullable()->after('review_viewed_at');
            $table->char('review_hash', 64)->nullable()->after('review_confirmed_at')->comment('Hash of the details and terms the customer confirmed');
            $table->string('review_confirmed_via', 10)->nullable()->after('review_hash')->comment('link = customer on the link; staff = officer assisted');
            $table->string('review_reason', 255)->nullable()->after('review_confirmed_via')->comment('Why an officer assisted (staff-assisted confirmation)');
        });
    }

    public function down(): void
    {
        Schema::table('loan_parties', function (Blueprint $table) {
            $table->dropUnique(['review_token_hash']);
            $table->dropColumn(['review_token_hash', 'review_sent_at', 'review_expires_at', 'review_viewed_at', 'review_confirmed_at', 'review_hash', 'review_confirmed_via', 'review_reason']);
        });
    }
};
