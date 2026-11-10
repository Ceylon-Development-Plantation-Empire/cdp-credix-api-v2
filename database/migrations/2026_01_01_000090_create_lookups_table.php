<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business-editable code lists: banks, income / expense / asset / liability categories, guarantor
 * relationships, branch types, employee types, designation levels and custom-field definitions.
 * One row per value; "type" names the list.
 *
 * DECISION (the Apache Fineract m_code_value pattern): records reference a value through a real
 * foreign key on lookups.id (RESTRICT), so a value in use can never be deleted, and the API keeps
 * speaking the stable "code". That the value belongs to the right list is checked by the
 * FormRequest / model (App\Rules\LookupCode); a plain shared code table without foreign keys
 * (the "one true lookup table" anti-pattern) is avoided. Values the money code depends on keep
 * their own tables (charge_types, gl_accounts, document_types); code-driven states stay enums.
 *
 * History is preserved: a used value is deactivated or end-dated, never deleted, so old records
 * keep their original label. The audit log records who changed a value (no user FK here, because
 * this table is created before users exist).
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('lookups', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->comment('Which list: bank, financial_category.income, guarantor_relationship …');
            $table->string('code', 60)->comment('Stable value sent and returned by the API');
            $table->string('label', 150)->comment('Shown to users; may be renamed freely');
            $table->string('description', 500)->nullable();
            $table->json('meta')->nullable()->comment('Extra attributes, e.g. {"weight": 30} or custom-field rules');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            // A system value is relied on by code or seeded rules: it can be relabelled, not recoded or removed.
            $table->boolean('is_system')->default(false);
            $table->date('effective_from')->nullable()->comment('Null = valid since always');
            $table->date('effective_to')->nullable()->comment('Null = still valid');
            $table->timestamps();

            $table->unique(['type', 'code']);
            // Dropdowns: the active values of one list in display order.
            $table->index(['type', 'is_active', 'sort_order']);
        });

        CheckConstraint::add('lookups', [
            'lookups_effective_period_check' => 'effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('lookups');
    }
};
