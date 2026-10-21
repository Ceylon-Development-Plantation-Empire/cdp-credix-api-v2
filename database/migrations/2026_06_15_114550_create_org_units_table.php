<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The organisation structure in one table (adjacency list): company groups and the geographic
     * hierarchy country → province → zone → region. Branches and employees point at these units.
     *
     * unit_type says what a row is (App\Enums\OrgUnitType); parent_id points one level up. A new
     * level later (an "area" between region and branch, say) is a new type, not a new table.
     * The Country, Province, Zonal, Region and Group models each read their own type.
     *
     * Units are never deleted while anything points at them (restrict), only switched off.
     */
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->id();
            $table->string('unit_type', 20);
            $table->foreignId('parent_id')->nullable()->constrained('org_units')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // A code is unique within its level (a province and a region may share "WP").
            $table->unique(['unit_type', 'code']);
            $table->index(['unit_type', 'parent_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('org_units');
    }
};
