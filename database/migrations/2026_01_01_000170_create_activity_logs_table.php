<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail: who did what, when, from where, with the old and new values in payload.
 *
 * Append-only: rows are written by the senior's ActivityLogTrait (and the audit observer through
 * it) and never updated or deleted by the application (App\Models\ActivityLog refuses both; in
 * production the database user should have no UPDATE/DELETE grant on this table). Hence no
 * updated_at column.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            // Restrict: users are never deleted, so the author of an entry is never lost.
            $table->foreignId('user_id')->nullable()->comment('Null for system jobs and failed logins')
                ->constrained('users')->restrictOnDelete();
            $table->string('action', 50)->comment('CREATE, UPDATE, DELETE, LOGIN, APPROVE …');
            $table->string('module', 100)->comment('Area / model, e.g. Loan, Customer, Setting');
            // TEXT: descriptions can carry a full error message; a VARCHAR limit would make the insert fail.
            $table->text('description');
            $table->json('payload')->nullable()->comment('Context: subject, old and new values (secrets redacted, PII masked)');
            $table->string('level', 20)->default('info')->comment('info, warning, error, critical');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('method', 10)->nullable();
            $table->timestamp('created_at')->nullable();

            // The audit screen filters by module / user / action over a date range.
            $table->index(['module', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
