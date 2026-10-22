<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Typed system settings the business changes without a deploy (limits, grace days, penalties,
 * thresholds, feature flags). Each row carries its own validation rules, so a new setting is a new
 * seeded row and is safely editable straight away. Every change is written to the audit log.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique()->comment('Dotted name read by the code, e.g. repayment.grace_period_days');
            $table->string('group', 50)->index()->comment('Settings screen section');
            $table->string('type', 20)->comment('App\Enums\SettingType: integer, decimal, boolean, string, json');
            // TEXT: JSON settings (provision table, allocation order) can be long.
            $table->text('value')->nullable()->comment('Stored as text, cast by type; null = use the code default');
            $table->json('rules')->comment('Laravel validation rules per field, e.g. {"value": "required|integer|min:0"}');
            $table->string('label', 150);
            $table->string('description', 500)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
