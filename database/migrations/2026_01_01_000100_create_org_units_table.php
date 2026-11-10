<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The organisation structure in one adjacency-list table: company groups and the geographic
 * hierarchy country → province → zone → region. Branches and employees point at these units.
 *
 * DECISION: one table with a unit_type instead of five tables (countries, provinces …). A new
 * level later (an "area" between region and branch) is a new type value, not a new table.
 * The Country, Province, Zonal, Region and Group models each read their own type (STI).
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->id();
            $table->string('unit_type', 20)->comment('App\Enums\OrgUnitType: group, country, province, zone, region');
            // Restrict: a unit is switched off, never deleted while anything points at it.
            $table->foreignId('parent_id')->nullable()->comment('The unit one level up; null for a country or a group')
                ->constrained('org_units')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('code', 50);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // A code is unique within its level (a province and a region may both be "WP").
            $table->unique(['unit_type', 'code']);
            // Children of a unit, filtered by level (dropdowns, tree walks).
            $table->index(['unit_type', 'parent_id']);
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('org_units');
    }
};
