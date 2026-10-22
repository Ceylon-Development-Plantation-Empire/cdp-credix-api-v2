<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership of customers in borrower groups, with history (a person may leave and join another
 * group). DECISION: a surrogate id is kept (not a pure pivot) because one person can have several
 * past memberships of the same group, each with its own dates and role.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('borrower_group_members', function (Blueprint $table) {
            $table->id();
            // Restrict: membership history is evidence for group loans.
            $table->foreignId('borrower_group_id')->constrained('borrower_groups')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('role', 20)->default('member')->comment('App\Enums\GroupMemberRole');
            $table->date('joined_on');
            $table->date('left_on')->nullable()->comment('Null while a current member');
            // Equals customer_id while the person is a current member, null once they leave. The unique
            // index then allows any number of past memberships but only ONE current group per person
            // (decision D-7), enforced by the database even under parallel requests.
            $table->unsignedBigInteger('active_member_key')->nullable()
                ->storedAs('case when left_on is null then customer_id else null end');
            $table->timestamps();

            $table->unique('active_member_key');
            $table->index(['borrower_group_id', 'left_on']);
        });

        CheckConstraint::add('borrower_group_members', [
            'borrower_group_members_period_check' => 'left_on IS NULL OR left_on >= joined_on',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrower_group_members');
    }
};
