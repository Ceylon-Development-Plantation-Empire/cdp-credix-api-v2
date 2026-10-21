<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The document rules as data. Rows with the same (product, party role, employment status,
     * requirement group) form one requirement; "rule" says whether any one or all of its document
     * types are needed. product_id null means the rule applies to every product.
     *
     * Example (identity proof, any):  NIC copy | Passport copy | Driving licence copy
     * Example (employment, employed, all): salary confirmation | employment letter | 3 salary slips
     */
    public function up(): void
    {
        Schema::create('document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->string('party_role', 20);                 // App\Enums\PartyRole
            $table->string('employment_status', 20)->nullable(); // App\Enums\EmploymentStatus, null = any
            $table->string('requirement_group', 60);
            $table->string('rule', 10);                       // App\Enums\RequirementRule
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
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_requirements');
    }
};
