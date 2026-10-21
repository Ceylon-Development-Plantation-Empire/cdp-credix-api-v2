<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every person on a loan and the role they play: borrower, co-borrower, group member or
     * guarantor. Replaces the old co-borrower, group member and guarantor tables; a guarantor
     * is a normal customer, so "one person = one record" and the guarantee limit is exact.
     *
     * open_loan_key is the database guarantee behind "one open loan per person" (rule L-1).
     * The service sets it to the customer id on the borrowing parties while the loan is open and
     * clears it when the loan closes. Being UNIQUE, two parallel applications for the same person
     * can never both succeed, whatever the timing.
     */
    public function up(): void
    {
        Schema::create('loan_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('role', 20);                          // App\Enums\PartyRole
            $table->string('relationship', 30)->nullable();      // guarantor ↔ borrower: family, employer, friend …
            $table->timestamp('consented_at')->nullable();       // OTP: borrower accepted the offer / guarantor agreed
            $table->char('consent_hash', 64)->nullable();        // hash of the terms that were agreed
            $table->timestamp('invoked_at')->nullable();         // this guarantor paid for the borrower (rule G-3)
            $table->timestamp('released_at')->nullable();        // loan closed → guarantee / obligation released
            $table->unsignedBigInteger('open_loan_key')->nullable()->unique();
            $table->timestamps();

            // Nobody can be borrower and guarantor on the same loan (rule G-4).
            $table->unique(['loan_id', 'customer_id']);
            $table->index(['customer_id', 'role', 'released_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_parties');
    }
};
