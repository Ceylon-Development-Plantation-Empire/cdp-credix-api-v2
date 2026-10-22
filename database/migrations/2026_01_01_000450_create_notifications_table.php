<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one record of everything sent to anyone.
 *
 * channel "database" rows are Laravel's own in-app alerts for staff users (Laravel writes them with
 * its standard columns). "sms" / "email" rows are outbound messages to customers: written before
 * the message is queued (outbox), then marked sent or failed by the job, so no message is lost or
 * sent twice.
 *
 * SECURITY: the text of an outbound message is never stored (data stays "{}"), only its hash, so
 * the table never holds an OTP or personal details; the hash also skips the same message to the
 * same number within a few minutes.
 *
 * DECISION: one table instead of notifications + notification_logs; the models split them by channel.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type')->comment('Laravel notification class, or outbound.<event>');
            $table->string('channel', 20)->default('database')->comment('database | sms | email');
            $table->string('event', 60)->nullable()->comment('App\Enums\NotificationEvent (outbound only)');
            $table->string('notifiable_type', 60)->comment('Morph alias: user (in-app) or customer (outbound)');
            $table->unsignedBigInteger('notifiable_id');
            $table->foreignId('loan_id')->nullable()->constrained('loans')->restrictOnDelete();
            $table->text('data')->comment('In-app alert payload; "{}" for outbound rows');
            $table->timestamp('read_at')->nullable();

            /* Outbound delivery */
            $table->string('destination', 150)->nullable()->comment('E.164 phone or email address');
            $table->foreignId('template_id')->nullable()->constrained('templates')->restrictOnDelete();
            $table->char('message_hash', 64)->nullable();
            $table->string('status', 20)->nullable()->comment('App\Enums\NotificationStatus');
            $table->string('provider_reference', 100)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            $table->index(['destination', 'message_hash', 'created_at'], 'notifications_dedupe_index');
            $table->index(['channel', 'event', 'created_at']);
            $table->index(['channel', 'status']);
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
