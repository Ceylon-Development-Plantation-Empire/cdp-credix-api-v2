<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lending-margin plans are gone: a loan application only collects data, and the approver decides the
 * amount and term. Collateral is still recorded, but no percentage of its value limits the loan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collaterals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lending_margin_plan_id');
        });

        Schema::dropIfExists('lending_margin_plans');

        // Role grants of these permissions go with them (role_has_permissions cascades).
        DB::table('permissions')->whereIn('name', ['Lending Margin Index', 'Lending Margin Manage'])->delete();
    }

    public function down(): void
    {
        Schema::create('lending_margin_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collateral_type_id')->comment('lookups.type = collateral_type')->constrained('lookups')->restrictOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->decimal('percentage', 5, 2);
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        CheckConstraint::add('lending_margin_plans', [
            'lending_margin_plans_percentage_check' => 'percentage > 0 AND percentage <= 100',
        ]);

        Schema::table('collaterals', function (Blueprint $table) {
            $table->foreignId('lending_margin_plan_id')->nullable()->after('collateral_type_id')->constrained('lending_margin_plans')->restrictOnDelete();
        });
    }
};
