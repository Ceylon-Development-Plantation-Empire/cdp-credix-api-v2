<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every natural person the company deals with: borrower, co-borrower, group member, guarantor.
 * One person = one row, so limits such as "two guarantees per person" are exact.
 *
 * SECURITY: the ID number is encrypted at rest. id_number_hash is an HMAC-SHA256 blind index of
 * the normalised number (old 9-digit + V NICs are converted to the 12-digit form first), so the
 * unique key on (id_type, id_number_hash) still stops the same person registering twice with the
 * old and the new NIC, and exact search works without decrypting anything.
 *
 * DECISION: phone numbers stay in clear (E.164): they are searched by partial match and are what
 * the SMS service is given; they are masked in every response for users without the sensitive
 * permission.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique()->comment('Used in URLs and responses; the numeric id stays internal');
            $table->string('customer_no', 20)->nullable()->unique()->comment('CUS-000123; set right after the insert from the id');
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            /* Identity */
            $table->string('id_type', 20)->comment('App\Enums\IdType');
            $table->text('id_number')->comment('Encrypted (APP_KEY), as entered');
            $table->char('id_number_hash', 64)->comment('HMAC-SHA256 blind index of the normalised ID number');
            $table->string('full_name', 150);
            $table->string('name_with_initials', 100);
            $table->date('date_of_birth');
            $table->string('gender', 10)->nullable()->comment('App\Enums\Gender; null = not stated (passport holders)');
            $table->char('preferred_language', 2)->default('en')->comment('App\Enums\Language: SMS and letters');

            /* Contact (E.164: +94XXXXXXXXX) */
            $table->string('phone_primary', 20)->comment('Receives OTP codes and notices');
            $table->string('phone_secondary', 20)->nullable();
            $table->string('whatsapp_number', 20)->nullable();
            $table->string('email', 150)->nullable();

            /* Residential address */
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('landmark', 150)->nullable()->comment('How a field officer finds the house');
            $table->string('city', 100);
            $table->string('gn_division', 100);
            $table->string('ds_division', 100);
            $table->string('district', 50);
            $table->string('province', 50);
            $table->string('postal_code', 10)->nullable();

            /* Employment (current); income and expenses live in customer_financials */
            $table->string('employment_status', 20)->comment('App\Enums\EmploymentStatus; drives the document rules');
            $table->string('occupation', 100)->nullable();
            $table->string('employer_name', 150)->nullable();
            $table->string('employer_address')->nullable();
            $table->string('employer_phone', 20)->nullable();
            $table->string('employer_email', 150)->nullable();
            $table->string('business_name', 150)->nullable();
            $table->string('business_registration_no', 50)->nullable();
            $table->string('business_nature', 100)->nullable();
            $table->string('business_address')->nullable();
            $table->string('business_phone', 20)->nullable();
            $table->string('business_email', 150)->nullable();
            $table->foreignId('recommended_by_employee_id')->nullable()->constrained('employees')->restrictOnDelete();

            /* KYC: the customer confirms these details by reading back an SMS code */
            $table->string('kyc_status', 20)->default('pending')->comment('App\Enums\KycStatus');
            $table->timestamp('kyc_verified_at')->nullable();
            $table->timestamp('kyc_expires_at')->nullable();
            $table->char('kyc_snapshot_hash', 64)->nullable()->comment('Fingerprint of the confirmed details; a change sends KYC back to pending');
            $table->smallInteger('credit_score')->nullable()->comment('Recomputed from repayment history; null = no history yet');
            $table->json('meta')->nullable()->comment('Custom attributes (lookups.type = custom_field.customer)');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id_type', 'id_number_hash']);
            $table->index(['branch_id', 'is_active']);
            $table->index('phone_primary');
            $table->index('full_name');
            $table->index('district');
            $table->index('kyc_status');
        });

        CheckConstraint::add('customers', [
            'customers_credit_score_check' => 'credit_score IS NULL OR credit_score BETWEEN 0 AND 1000',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
