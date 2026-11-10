<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank accounts a disbursement can be paid to.
 *
 * SECURITY: the account number is encrypted at rest. account_last4 is kept in clear for display,
 * and account_hash (HMAC blind index) refuses the same account twice without decrypting.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('customer_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('bank_id')->comment('lookups.id where type = bank')->constrained('lookups')->restrictOnDelete();
            $table->string('branch_name', 100)->nullable();
            $table->string('account_holder_name', 150);
            $table->text('account_number')->comment('Encrypted (APP_KEY)');
            $table->char('account_last4', 4);
            $table->char('account_hash', 64)->comment('HMAC-SHA256 blind index of the account number');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['customer_id', 'account_hash']);
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_bank_accounts');
    }
};
