<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Borrower groups for group loans (Women's Empowerment, Community Development Fund).
 * Named borrower_groups because "group" is also a company group in org_units.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('borrower_groups', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('group_no', 20)->nullable()->unique()->comment('GRP-000012; set right after the insert');
            $table->string('name', 150);
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('formed_on');
            $table->string('meeting_day', 10)->nullable()->comment('monday … sunday');
            $table->string('status', 20)->default('active')->comment('App\Enums\BorrowerGroupStatus');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrower_groups');
    }
};
