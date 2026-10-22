<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job titles within a department.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            // Restrict: deleting a department must never delete its designations (and their employees).
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('code', 50)->unique();
            $table->foreignId('level_id')->comment('lookups.id where type = designation_level (entry, mid, senior …)')
                ->constrained('lookups')->restrictOnDelete();
            // Seniority used to order designations; defaults from the level lookup's meta.weight.
            $table->unsignedSmallInteger('order_weight')->default(0);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['department_id', 'is_active']);
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('designations');
    }
};
