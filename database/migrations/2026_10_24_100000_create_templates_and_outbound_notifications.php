<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * templates: every SMS / email / agreement text in one table, per language. The most specific
     * one wins: product → category (and its parents) → global. Texts use {{placeholders}} from a
     * fixed list only, so a template can never run code or read data it was not given.
     *
     * notifications (the starter's Laravel table) becomes the one record of everything sent to
     * anyone. channel says what a row is: "database" is an in-app alert for a staff user (written
     * by Laravel as before), "sms" / "email" are outbound messages to customers. An outbound row is
     * written before the message is queued (outbox), then marked sent or failed by the job, so no
     * message is ever lost or sent twice. Its text is not kept, only a hash, so the table never holds
     * an OTP or personal details; the hash also skips the same message to the same number within a
     * few minutes.
     */
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80);                     // loan.approved, installment.due-reminder …
            $table->string('channel', 20);                  // App\Enums\NotificationChannel
            $table->char('language', 2)->default('en');
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->cascadeOnDelete();
            $table->string('subject', 150)->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['slug', 'channel', 'language', 'product_id', 'category_id'], 'templates_resolution_unique');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->string('channel', 20)->default('database')->after('type'); // database | sms | email
            $table->string('event', 60)->nullable()->after('channel');        // App\Enums\NotificationEvent
            $table->foreignId('loan_id')->nullable()->after('notifiable_id')->constrained('loans')->nullOnDelete();
            $table->string('destination', 150)->nullable();
            $table->foreignId('template_id')->nullable()->constrained('templates')->nullOnDelete();
            $table->char('message_hash', 64)->nullable();
            $table->string('status', 20)->nullable();                         // queued, sent, failed, skipped
            $table->string('provider_reference', 100)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->index(['destination', 'message_hash', 'created_at'], 'notifications_dedupe_index');
            $table->index(['channel', 'event', 'created_at']);
            $table->index(['channel', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_dedupe_index');
            $table->dropIndex(['channel', 'event', 'created_at']);
            $table->dropIndex(['channel', 'status']);
            $table->dropConstrainedForeignId('loan_id');
            $table->dropConstrainedForeignId('template_id');
            $table->dropColumn(['channel', 'event', 'destination', 'message_hash', 'status', 'provider_reference', 'error', 'sent_at']);
        });

        Schema::dropIfExists('templates');
    }
};
