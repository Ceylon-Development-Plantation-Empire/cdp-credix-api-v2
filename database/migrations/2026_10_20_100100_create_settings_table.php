<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * System settings that business users can change without a deploy (guarantee limit, grace days,
     * penalty, recovery thresholds …). Each row carries its own validation rules, so a new setting is
     * a new seeded row and never needs a code change to be editable safely.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('group', 50)->index();
            $table->string('type', 20); // App\Enums\SettingType
            $table->text('value')->nullable();
            // JSON map of field => Laravel rules, e.g. {"value": "required|integer|min:0"}.
            $table->json('rules');
            $table->string('label');
            $table->text('description')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
