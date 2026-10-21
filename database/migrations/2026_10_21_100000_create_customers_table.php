<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every natural person the company deals with: borrower, co-borrower, group member and
     * guarantor. One person = one row, so limits such as "two guarantees per person" are exact.
     *
     * id_number_normalized holds the 12-digit NIC (old 9-digit + V/X numbers are converted),
     * and the unique index on (id_type, id_number_normalized) stops the same person being
     * registered twice with the old and the new NIC.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('customer_no', 20)->nullable()->unique(); // CUS-000123, set right after insert
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            /* Identity */
            $table->string('id_type', 20);               // App\Enums\IdType
            $table->string('id_number', 30);             // as entered
            $table->string('id_number_normalized', 30);
            $table->string('full_name', 150);
            $table->string('name_with_initials', 100);
            $table->date('date_of_birth');
            $table->string('gender', 10)->nullable();    // App\Enums\Gender
            $table->char('preferred_language', 2)->default('en');

            /* Contact (phones stored as E.164: +94XXXXXXXXX) */
            $table->string('phone_primary', 15)->index();
            $table->string('phone_secondary', 15)->nullable();
            $table->string('whatsapp_number', 15)->nullable();
            $table->string('email', 150)->nullable();

            /* Residential address */
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('city', 100);
            $table->string('gn_division', 100);
            $table->string('ds_division', 100);
            $table->string('district', 50)->index();
            $table->string('province', 50);
            $table->string('postal_code', 10)->nullable();

            /* Employment (current); income and expenses live in customer_financials */
            $table->string('employment_status', 20);     // App\Enums\EmploymentStatus
            $table->string('occupation', 100)->nullable();
            $table->string('employer_name', 150)->nullable();
            $table->string('employer_address')->nullable();
            $table->string('business_name', 150)->nullable();
            $table->string('business_registration_no', 50)->nullable();

            $table->foreignId('recommended_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            /* KYC: details confirmed by the customer through OTP */
            $table->string('kyc_status', 20)->default('pending')->index(); // App\Enums\KycStatus
            $table->timestamp('kyc_verified_at')->nullable();
            $table->timestamp('kyc_expires_at')->nullable();
            $table->char('kyc_snapshot_hash', 64)->nullable();

            $table->smallInteger('credit_score')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id_type', 'id_number_normalized']);
            $table->index(['branch_id', 'is_active']);
            $table->index('full_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
