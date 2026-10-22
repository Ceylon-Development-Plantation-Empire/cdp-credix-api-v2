<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fee and charge types, managed by the business (processing fee, documentation fee, insurance,
 * stamp duty, a new "legal fee" …).
 *
 * behaviour is the only part the code knows (App\Enums\ChargeBehaviour): an upfront fee is
 * collected at disbursement or with the first installment; late payment, early settlement and
 * safekeeping are raised by the servicing engine. Any number of upfront fee types can be added
 * as data; the engine-driven behaviours exist once each (system rows).
 *
 * display_group lets quotes and statements show one combined line ("Service charges") with the
 * itemised breakdown underneath.
 *
 * DECISION: its own table (not the generic lookups) because product charges and ledger
 * transactions reference it with a real foreign key.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('charge_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique()->comment('Stable identifier, e.g. processing_fee');
            $table->string('name', 100);
            $table->string('display_group', 100)->comment('Combined line on quotes and statements, e.g. Service charges');
            $table->string('behaviour', 30)->comment('App\Enums\ChargeBehaviour: upfront, late_payment, early_settlement, safekeeping');
            $table->json('allowed_calculations')->comment('App\Enums\ChargeCalculation values a product may use');
            $table->json('allowed_recurrences')->comment('App\Enums\ChargeRecurrence values a product may use');
            $table->string('description', 500)->nullable();
            $table->boolean('is_system')->default(false)->comment('Seeded engine row: cannot be deactivated or recoded');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['behaviour', 'is_active']);
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('charge_types');
    }
};
