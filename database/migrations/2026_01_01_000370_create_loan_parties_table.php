<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every person on a loan and their role: borrower, co-borrower, group member or guarantor.
 * A guarantor is a normal customer, so "one person = one record" and the guarantee limit is exact.
 *
 * open_loan_key is the database guarantee behind "one open loan per person" (rule L-1): the
 * service sets it to the customer id on the borrowing parties while the loan is open and clears
 * it when the loan closes. Being UNIQUE, two parallel applications for the same person can never
 * both succeed.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('loan_parties', function (Blueprint $table) {
            $table->id();
            // Restrict: the parties are part of the loan contract.
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('role', 20)->comment('App\Enums\PartyRole');
            $table->foreignId('relationship_id')->nullable()->comment('Guarantor ↔ borrower; lookups.type = guarantor_relationship')
                ->constrained('lookups')->restrictOnDelete();
            $table->timestamp('consented_at')->nullable()->comment('Borrower accepted the offer / guarantor agreed (OTP)');
            $table->char('consent_hash', 64)->nullable()->comment('Hash of the terms that were agreed');
            $table->timestamp('invoked_at')->nullable()->comment('This guarantor paid for the borrower (rule G-3)');
            $table->timestamp('released_at')->nullable()->comment('Loan closed: guarantee / obligation released');
            $table->unsignedBigInteger('open_loan_key')->nullable()->unique();
            $table->timestamps();

            // Nobody can be borrower and guarantor on the same loan (rule G-4).
            $table->unique(['loan_id', 'customer_id']);
            $table->index(['customer_id', 'role', 'released_at']);
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_parties');
    }
};
