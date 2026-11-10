<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deleted loan keeps its guarantor links and collateral, so "Loan Restore" can bring the whole
 * application back. Removing a guarantor or a collateral by hand still erases the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['loan_parties', 'collaterals'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (['loan_parties', 'collaterals'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
