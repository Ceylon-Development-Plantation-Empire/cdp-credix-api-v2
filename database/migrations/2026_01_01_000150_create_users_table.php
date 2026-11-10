<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logins of the back office (admin, staff) and of external recovery agencies.
 *
 * Customers never log in to this portal. There are no password_reset_tokens or sessions tables:
 * the API is stateless (JWT in an HttpOnly cookie) and passwords are reset by an administrator.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            // Staff and admins sign in with their email; the username is only a display handle.
            $table->string('username', 60)->nullable()->unique();
            $table->string('email', 150)->unique()->comment('Login identifier');
            // Mobile for the staff-login SMS code (setting security.staff_login_otp). Null = use the
            // employee record's phone; admins without an employee record need it set.
            $table->string('phone', 20)->nullable()->comment('E.164');
            $table->string('password')->comment('bcrypt hash; never reversible');
            $table->string('user_type', 20)->default('staff')->comment('App\Enums\UserType: admin, staff, agency');
            // Unique: one login per employee. Restrict: users are deactivated, never deleted (audit trail).
            $table->foreignId('employee_id')->nullable()->unique()->constrained('employees')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('can_login')->default(true);

            /* SECURITY: credential lifecycle */
            $table->boolean('must_change_password')->default(true)->comment('Set for a new or reset password; the user must choose their own first');
            $table->timestamp('password_changed_at')->nullable();
            // Every issued token carries this number; raising it (password change, logout everywhere,
            // deactivation) makes all earlier tokens invalid at once.
            $table->unsignedInteger('token_version')->default(0);
            $table->unsignedTinyInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable()->comment('Login refused until this moment after too many wrong passwords');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_type', 'is_active']);
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
