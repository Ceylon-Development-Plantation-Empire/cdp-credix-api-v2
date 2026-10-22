<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The document rules as data. Rows with the same (product, party role, employment status,
 * requirement group) form one requirement; "rule" says whether any one or all of its document
 * types are needed. product_id null means the rule applies to every product.
 *
 *   identity proof, any:          NIC copy | Passport copy | Driving licence copy
 *   employment, employed, all:    salary confirmation | employment letter | 3 salary slips
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('document_requirements', function (Blueprint $table) {
            $table->id();
            // Cascade is safe: configuration rows of a product.
            $table->foreignId('product_id')->nullable()->comment('Null = every product')->constrained('products')->cascadeOnDelete();
            $table->string('party_role', 20)->comment('App\Enums\PartyRole');
            $table->string('employment_status', 20)->nullable()->comment('App\Enums\EmploymentStatus; null = any');
            $table->string('requirement_group', 60);
            $table->string('rule', 10)->comment('App\Enums\RequirementRule: any, all');
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->unsignedTinyInteger('min_count')->default(1);
            $table->boolean('is_mandatory')->default(true);
            $table->timestamps();

            $table->index(['party_role', 'employment_status']);
            $table->unique(
                ['product_id', 'party_role', 'employment_status', 'requirement_group', 'document_type_id'],
                'document_requirements_unique_rule'
            );
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_requirements');
    }
};
