<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user can be deleted for good (owner decision 27 Oct 2026). Every record that named the user keeps its row and loses only the
 * link, so the two columns that could not be empty become nullable, and the audit log keeps the person's name as text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable()->change();
        });

        Schema::table('employee_hierarchy_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('changed_by')->nullable()->change();
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('user_name', 191)->nullable()->after('user_id')->comment('Set when the user is deleted, so the entry still says who did it');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn('user_name');
        });
    }
};
