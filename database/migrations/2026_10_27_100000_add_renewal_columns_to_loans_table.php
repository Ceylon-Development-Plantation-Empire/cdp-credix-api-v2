<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Gold loan renewal: the customer pays what is due, the same gold stays pledged and the
     * principal runs for a new term (a new schedule version on the same loan).
     *
     * schedule_starts_on is the day the schedule in force began (disbursement, renewal or
     * restructure); interest for an early payoff accrues from it, not from the first disbursement.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->unsignedSmallInteger('renewal_count')->default(0)->after('maturity_date');
            $table->date('schedule_starts_on')->nullable()->after('renewal_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['renewal_count', 'schedule_starts_on']);
        });
    }
};
