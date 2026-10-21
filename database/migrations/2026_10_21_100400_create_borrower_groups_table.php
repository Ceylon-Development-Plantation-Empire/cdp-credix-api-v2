<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Borrower groups for group loans (Women's Empowerment, Community Development Fund).
     * Named borrower_groups because the starter kit already has an org-structure "groups" table.
     */
    public function up(): void
    {
        Schema::create('borrower_groups', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('group_no', 20)->nullable()->unique(); // GRP-000012, set right after insert
            $table->string('name', 150);
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('formed_on');
            $table->string('meeting_day', 10)->nullable(); // monday … sunday
            $table->string('status', 20)->default('active')->index(); // App\Enums\BorrowerGroupStatus
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('borrower_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_group_id')->constrained('borrower_groups')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('role', 20)->default('member'); // App\Enums\GroupMemberRole
            $table->date('joined_on');
            $table->date('left_on')->nullable();
            // Equals customer_id while the person is a current member, null once they leave.
            // The unique index then allows any number of past memberships but only ONE current
            // group per person (decision D-7), enforced by the database even under parallel requests.
            $table->unsignedBigInteger('active_member_key')->nullable()->storedAs('case when left_on is null then customer_id else null end');
            $table->timestamps();

            $table->unique('active_member_key');
            $table->index(['borrower_group_id', 'left_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrower_group_members');
        Schema::dropIfExists('borrower_groups');
    }
};
