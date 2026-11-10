<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR departments. head_id points at employees; that foreign key is added by the employees
 * migration because the two tables reference each other.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 50)->unique();
            $table->string('description', 500)->nullable();
            // Unique: one person heads at most one department.
            $table->unsignedBigInteger('head_id')->nullable()->unique()->comment('employees.id of the head of department');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
