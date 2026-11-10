<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-role loan approval limit is gone (the Loan Approve permission alone decides), so the column goes too
 * and the role API stops returning it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('max_approval_amount');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->decimal('max_approval_amount', 19, 4)->nullable()->after('is_protected');
        });
    }
};
