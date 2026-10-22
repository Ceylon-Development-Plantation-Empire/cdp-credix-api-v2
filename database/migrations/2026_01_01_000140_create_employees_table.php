<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employees (the HR record behind a staff user) and the department-head link.
 *
 * SECURITY: the national ID number is personal data. It is stored encrypted (Eloquent "encrypted"
 * cast, AES-256 with APP_KEY) and found through id_number_hash, a keyed HMAC blind index, so a
 * database copy alone reveals no ID numbers and uniqueness still holds.
 */
return new class extends Migration
{
    /**
     * Create the table and the departments.head_id foreign key.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            /* Name */
            $table->string('f_name', 100);
            $table->string('l_name', 100);
            $table->string('full_name', 150);
            $table->string('name_with_initials', 100);
            $table->string('employee_code', 30)->nullable()->unique()->comment('HR number; null until HR assigns one');

            /* Reporting line and place in the organisation (restrict: reference data is switched off, never deleted) */
            $table->foreignId('reporting_manager_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('province_id')->nullable()->constrained('org_units')->restrictOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('org_units')->restrictOnDelete();
            $table->foreignId('zonal_id')->nullable()->constrained('org_units')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->comment('Null for head-office staff')->constrained('branches')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('designation_id')->constrained('designations')->restrictOnDelete();

            /* Identity (DECISION: a lookup FK and an enum-backed string instead of MySQL ENUMs) */
            $table->foreignId('employee_type_id')->comment('lookups.id where type = employee_type (permanent, contract …)')
                ->constrained('lookups')->restrictOnDelete();
            $table->string('id_type', 20)->comment('nic, passport, driving_license, other (starter HR values)');
            $table->text('id_number')->comment('Encrypted (APP_KEY); never searched directly');
            $table->char('id_number_hash', 64)->unique()->comment('HMAC-SHA256 blind index of the normalised ID number');
            $table->date('date_of_birth');

            /* Contact (phones in E.164) */
            $table->string('email', 150)->unique();
            $table->string('phone', 20)->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->default('Sri Lanka');
            $table->string('postal_code', 10)->nullable();
            $table->string('phone_primary', 20);
            $table->string('phone_secondary', 20)->nullable();
            $table->boolean('have_whatsapp')->default(false);
            $table->string('whatsapp_number', 20)->nullable();

            /* Employment period */
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable()->comment('Null while employed');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('head_id')->references('id')->on('employees')->restrictOnDelete();
        });

        CheckConstraint::add('employees', [
            'employees_period_check' => 'end_date IS NULL OR start_date IS NULL OR end_date >= start_date',
        ]);
    }

    /**
     * Drop the department-head key first, then the table.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['head_id']);
        });

        Schema::dropIfExists('employees');
    }
};
