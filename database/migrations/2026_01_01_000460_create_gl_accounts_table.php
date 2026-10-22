<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chart of accounts. System accounts (with a slug) are the ones the posting rules write to:
 * accountants may rename or renumber them, never retype or deactivate them. They are created by
 * Database\Seeders\ChartOfAccountsSeeder (system reference data).
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('gl_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('Account number shown to accountants');
            $table->string('name', 120);
            $table->string('type', 20)->comment('App\Enums\GlAccountType: asset, liability, equity, income, expense');
            $table->string('slug', 60)->nullable()->unique()->comment('Set on system accounts only; what the posting rules use');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('gl_accounts');
    }
};
