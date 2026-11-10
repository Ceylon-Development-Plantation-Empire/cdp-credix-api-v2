<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches: the unit every customer, loan, recovery case and journal belongs to (branch scoping).
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();

            /* Place in the organisation (all org_units rows; restrict: switched off, never deleted) */
            $table->foreignId('group_id')->nullable()->comment('Company group (org_units.unit_type = group)')
                ->constrained('org_units')->restrictOnDelete();
            $table->foreignId('province_id')->constrained('org_units')->restrictOnDelete();
            $table->foreignId('zone_id')->constrained('org_units')->restrictOnDelete();
            $table->foreignId('region_id')->constrained('org_units')->restrictOnDelete();

            $table->string('name', 150);
            $table->string('code', 20)->unique();

            /* Address and contact (phones in E.164) */
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city', 100);
            $table->string('postal_code', 10)->nullable();
            $table->string('phone_primary', 20);
            $table->string('phone_secondary', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('fax', 20)->nullable();

            $table->date('opening_date');
            // DECISION: a lookup value (type branch_type), not a MySQL ENUM, so the business can add
            // a branch type without an ALTER TABLE; the API keeps sending the code (main, city …).
            $table->foreignId('branch_type_id')->comment('lookups.id where type = branch_type')
                ->constrained('lookups')->restrictOnDelete();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });

        CheckConstraint::add('branches', [
            'branches_latitude_check' => 'latitude IS NULL OR latitude BETWEEN -90 AND 90',
            'branches_longitude_check' => 'longitude IS NULL OR longitude BETWEEN -180 AND 180',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
