<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * users:
     * - user_type becomes a plain string backed by App\Enums\UserType, because the system now has
     *   agency (external recovery agency) logins next to admin and staff.
     *   A string column lets us add a type later without an ALTER on an ENUM.
     * - The starter's User model already reads and writes the email verification columns, but the
     *   users migration never created them. They are added here.
     *
     * roles:
     * - max_approval_amount is the approval authority of the role. A user can only approve a loan up
     *   to the highest limit among their roles; null means the role cannot approve loans.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('user_type', 20)->default('staff')->change();
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->string('email_verification_token', 64)->nullable()->after('email_verified_at');
            $table->timestamp('email_verification_token_expires_at')->nullable()->after('email_verification_token');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->decimal('max_approval_amount', 15, 2)->nullable()->after('is_protected');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('max_approval_amount');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_verified_at', 'email_verification_token', 'email_verification_token_expires_at']);
            $table->enum('user_type', ['admin', 'staff'])->default('admin')->change();
        });
    }
};
