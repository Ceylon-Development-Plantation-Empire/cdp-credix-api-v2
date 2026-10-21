<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which product charge a raised charge is (safekeeping fee, early settlement fee …), so the
     * nightly run can tell how many months of safekeeping a loan has already been charged.
     */
    public function up(): void
    {
        Schema::table('loan_transactions', function (Blueprint $table) {
            $table->string('charge_type', 30)->nullable()->after('type'); // App\Enums\ChargeType
            $table->index(['loan_id', 'charge_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_transactions', function (Blueprint $table) {
            $table->dropIndex(['loan_id', 'charge_type']);
            $table->dropColumn('charge_type');
        });
    }
};
