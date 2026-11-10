<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop check constraint on products table and re-add without max_dsr_percent
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products DROP CHECK products_percent_check');
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_percent_check CHECK ((residual_value_percent IS NULL OR residual_value_percent BETWEEN 0 AND 100) AND (early_settlement_rebate_percent IS NULL OR early_settlement_rebate_percent BETWEEN 0 AND 100))');
        }

        // 2. Drop max_dsr_percent from products table
        if (Schema::hasColumn('products', 'max_dsr_percent')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('max_dsr_percent');
            });
        }

        // 3. Drop dsr_percent from loans table
        if (Schema::hasColumn('loans', 'dsr_percent')) {
            Schema::table('loans', function (Blueprint $table) {
                $table->dropColumn('dsr_percent');
            });
        }

        // 4. Delete setting affordability.max_dsr_percent from settings table
        DB::table('settings')->where('key', 'affordability.max_dsr_percent')->delete();
    }

    public function down(): void
    {
        // Restore columns
        if (! Schema::hasColumn('loans', 'dsr_percent')) {
            Schema::table('loans', function (Blueprint $table) {
                $table->decimal('dsr_percent', 7, 2)->nullable()->comment('Debt service ratio at assessment (%)');
            });
        }

        if (! Schema::hasColumn('products', 'max_dsr_percent')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('max_dsr_percent', 5, 2)->nullable()->comment('Null = setting affordability.max_dsr_percent');
            });

            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE products DROP CHECK products_percent_check');
                DB::statement('ALTER TABLE products ADD CONSTRAINT products_percent_check CHECK (((max_dsr_percent IS NULL OR max_dsr_percent BETWEEN 0 AND 100)) AND (residual_value_percent IS NULL OR residual_value_percent BETWEEN 0 AND 100) AND (early_settlement_rebate_percent IS NULL OR early_settlement_rebate_percent BETWEEN 0 AND 100))');
            }
        }

        DB::table('settings')->insertOrIgnore([
            'group' => 'loans',
            'key' => 'affordability.max_dsr_percent',
            'type' => 'decimal',
            'value' => '60',
            'rules' => json_encode(['value' => 'required|numeric|min:1|max:100']),
            'description' => 'Maximum debt service ratio (%)',
            'is_custom' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};

