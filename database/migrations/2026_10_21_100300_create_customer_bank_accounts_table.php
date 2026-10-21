<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The account number is encrypted at rest (it is what a disbursement is paid to), so a
     * database dump alone does not reveal it. account_last4 is kept in clear for display and
     * search, and account_hash (HMAC) lets us refuse the same account twice without decrypting.
     */
    public function up(): void
    {
        Schema::create('customer_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('bank_name', 100);
            $table->string('branch_name', 100)->nullable();
            $table->string('account_holder_name', 150);
            $table->text('account_number');   // encrypted cast
            $table->char('account_last4', 4);
            $table->char('account_hash', 64);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['customer_id', 'account_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_bank_accounts');
    }
};
