<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point-in-time reporting-manager assignments. Exactly one open row (effective_to IS NULL) per
 * employee; earlier rows are history and are never deleted.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('employee_hierarchy_histories', function (Blueprint $table) {
            $table->id();
            // Restrict: history survives; employees are deactivated, not deleted.
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('reporting_manager_id')->nullable()->comment('Null = top of the hierarchy')
                ->constrained('employees')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable()->comment('Null = the current assignment');
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // Point-in-time lookups: "who was X's manager on date D" and "who reported to M on date D".
            $table->index(['employee_id', 'effective_from', 'effective_to'], 'idx_emp_hierarchy_lookup');
            $table->index(['reporting_manager_id', 'effective_from', 'effective_to'], 'idx_mgr_subordinates_lookup');
        });

        CheckConstraint::add('employee_hierarchy_histories', [
            'employee_hierarchy_period_check' => 'effective_to IS NULL OR effective_to >= effective_from',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_hierarchy_histories');
    }
};
