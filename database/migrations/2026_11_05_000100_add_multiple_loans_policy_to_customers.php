<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('allow_multiple_loans')->default(false)->after('credit_score')->index();
            $table->unsignedSmallInteger('max_open_loans')->default(1)->after('allow_multiple_loans');
            $table->string('multiple_loans_reason', 500)->nullable()->after('max_open_loans');
            $table->foreignId('multiple_loans_approved_by')->nullable()->after('multiple_loans_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('multiple_loans_approved_at')->nullable()->after('multiple_loans_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('multiple_loans_approved_by');
            $table->dropColumn([
                'allow_multiple_loans',
                'max_open_loans',
                'multiple_loans_reason',
                'multiple_loans_approved_at',
            ]);
        });
    }
};

