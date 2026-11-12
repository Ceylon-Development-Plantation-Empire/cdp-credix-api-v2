<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secondary branches for staff employees.
 *
 * Primary branch is stored on employees.branch_id. This pivot table allows staff
 * (e.g. Regional Managers, floating loan officers, multi-branch coordinators)
 * to be assigned to multiple branches simultaneously.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_branches');
    }
};
