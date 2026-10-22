<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * After approval the officer phones the customer and records the answer: accepted, declined (the loan
 * is cancelled with the reason) or on hold (still open, with the reason). A loan on hold has the time
 * and the officer's note here; each answer is also a line on the loan timeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->timestamp('offer_on_hold_at')->nullable()->after('approved_at')->comment('Customer asked to wait; null = no hold');
            $table->string('offer_remarks', 1000)->nullable()->after('offer_on_hold_at')->comment('Why the customer put the offer on hold');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['offer_on_hold_at', 'offer_remarks']);
        });
    }
};
