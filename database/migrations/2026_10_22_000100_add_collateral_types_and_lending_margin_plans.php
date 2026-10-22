<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collateral types become data and lending-margin plans come back.
 *
 * 1. Collateral types move from a PHP enum to the lookups list "collateral_type", so head office can
 *    add new kinds of security. The five types the API has always accepted are inserted here with
 *    the same codes (system values), and the two tables that stored the type as text now point at
 *    the lookup by a real foreign key, like every other lookup (Fineract m_code_value pattern).
 * 2. lending_margin_plans: named plans per collateral type ("Property Mortgage Plan 2 — 50 %"),
 *    each with its own lending percentage, which head office can add and change at any time.
 *    A collateral may name the plan it was valued under (optional); its percentage is copied onto
 *    the collateral, so changing a plan later never changes an existing pledge.
 *
 * Written as a new migration (not an edit of the create migrations) so a database that already
 * holds data is upgraded with `php artisan migrate`, keeping every row.
 */
return new class extends Migration
{
    /**
     * The system collateral types as they are on 22 October 2026. A copy on purpose: a migration
     * must keep doing the same thing even if the application's definitions change later.
     */
    private const TYPES = [
        'property_mortgage' => ['Property mortgage', 10, ['valuation' => 'market_value', 'reference' => 'registration', 'fields' => ['deed_no' => ['required', 'string', 'max:50'], 'deed_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'notary' => ['nullable', 'string', 'max:150'], 'plan_no' => ['nullable', 'string', 'max:50'], 'lot_no' => ['nullable', 'string', 'max:20'], 'extent' => ['required', 'string', 'max:50'], 'address' => ['required', 'string', 'max:255']], 'documents' => ['property-deed', 'valuation-report']]],
        'vehicle' => ['Vehicle', 20, ['valuation' => 'market_value', 'reference' => 'registration', 'fields' => ['registration_no' => ['required', 'string', 'max:20'], 'chassis_no' => ['required', 'string', 'max:40'], 'engine_no' => ['required', 'string', 'max:40'], 'make' => ['required', 'string', 'max:50'], 'model' => ['required', 'string', 'max:50'], 'year' => ['required', 'integer', 'min:1950', 'max:next_year']], 'documents' => ['vehicle-cr', 'valuation-report']]],
        'gold' => ['Gold', 30, ['valuation' => 'gold_weight', 'reference' => 'custody', 'fields' => ['item' => ['required', 'string', 'max:100']], 'documents' => ['gold-valuation-slip']]],
        'fixed_deposit' => ['Fixed deposit lien', 40, ['valuation' => 'market_value', 'reference' => 'registration', 'fields' => ['account_no' => ['required', 'string', 'max:40'], 'institution' => ['required', 'string', 'max:150'], 'maturity_date' => ['nullable', 'date_format:Y-m-d']], 'documents' => []]],
        'cdp_investment' => ['CDP investment', 50, ['valuation' => 'market_value', 'reference' => 'registration', 'fields' => ['account_no' => ['required', 'string', 'max:40'], 'institution' => ['required', 'string', 'max:150'], 'maturity_date' => ['nullable', 'date_format:Y-m-d']], 'documents' => []]],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::TYPES as $code => [$label, $order, $meta]) {
            $exists = DB::table('lookups')->where('type', 'collateral_type')->where('code', $code)->exists();
            if (! $exists) {
                DB::table('lookups')->insert([
                    'type' => 'collateral_type', 'code' => $code, 'label' => $label, 'meta' => json_encode($meta),
                    'sort_order' => $order, 'is_active' => true, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        Schema::create('lending_margin_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collateral_type_id')->comment('lookups.type = collateral_type')->constrained('lookups')->restrictOnDelete();
            $table->string('code', 40)->unique()->comment('Stable; never changes once created');
            $table->string('name', 120);
            $table->decimal('percentage', 5, 2)->comment('% of the collateral value that can be lent');
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['collateral_type_id', 'is_active', 'sort_order'], 'lending_margin_plans_type_active_order_index');
        });

        CheckConstraint::add('lending_margin_plans', [
            'lending_margin_plans_percentage_check' => 'percentage > 0 AND percentage <= 100',
        ]);

        $this->typeToForeignKey('product_collateral_rules', 'collateral_type', after: 'product_id', unique: ['product_id']);
        $this->typeToForeignKey('collaterals', 'type', after: 'loan_id');

        Schema::table('collaterals', function (Blueprint $table) {
            $table->foreignId('lending_margin_plan_id')->nullable()->after('collateral_type_id')
                ->comment('Plan the item was valued under; its percentage is copied to ltv_percent')
                ->constrained('lending_margin_plans')->restrictOnDelete();
        });

        $this->forgetCachedList();
    }

    public function down(): void
    {
        Schema::table('collaterals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lending_margin_plan_id');
        });

        $this->foreignKeyToType('collaterals', 'type', after: 'loan_id');
        $this->foreignKeyToType('product_collateral_rules', 'collateral_type', after: 'product_id', unique: ['product_id']);

        Schema::dropIfExists('lending_margin_plans');
        // Nothing points at the collateral types any more: the columns that did are text again.
        DB::table('lookups')->where('type', 'collateral_type')->delete();
        $this->forgetCachedList();
    }

    /**
     * The lookups were written directly (not through the model), so drop the cached copy of the list
     * (App\Support\Lookups\LookupRegistry) or codes would be read against old ids.
     */
    private function forgetCachedList(): void
    {
        Cache::forget('lookups:v2:collateral_type');
    }

    /**
     * Replace a text column holding a collateral type code with collateral_type_id → lookups.id.
     *
     * @param  list<string>  $unique  columns the old code was unique together with
     */
    private function typeToForeignKey(string $table, string $column, string $after, array $unique = []): void
    {
        Schema::table($table, function (Blueprint $t) use ($after) {
            $t->foreignId('collateral_type_id')->nullable()->after($after)->comment('lookups.type = collateral_type');
        });

        foreach (self::TYPES as $code => $definition) {
            $id = DB::table('lookups')->where('type', 'collateral_type')->where('code', $code)->value('id');
            DB::table($table)->where($column, $code)->update(['collateral_type_id' => $id]);
        }

        if (DB::table($table)->whereNull('collateral_type_id')->exists()) {
            throw new RuntimeException("{$table} has a {$column} that is not a known collateral type; fix it before migrating.");
        }

        // MySQL uses the old unique index for the product foreign key; keep a plain index on the
        // leading column while the unique key is swapped, then drop it again.
        if ($unique) {
            Schema::table($table, fn (Blueprint $t) => $t->index($unique, "{$table}_swap_index"));
        }

        Schema::table($table, function (Blueprint $t) use ($column, $unique) {
            if ($unique) {
                $t->dropUnique([...$unique, $column]);
            }
            $t->dropColumn($column);
        });

        Schema::table($table, function (Blueprint $t) use ($unique) {
            $t->foreignId('collateral_type_id')->nullable(false)->change();
            $t->foreign('collateral_type_id')->references('id')->on('lookups')->restrictOnDelete();
            if ($unique) {
                $t->unique([...$unique, 'collateral_type_id']);
            }
        });

        if ($unique) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex("{$table}_swap_index"));
        }
    }

    /**
     * @param  list<string>  $unique
     */
    private function foreignKeyToType(string $table, string $column, string $after, array $unique = []): void
    {
        Schema::table($table, function (Blueprint $t) use ($column, $after) {
            $t->string($column, 30)->nullable()->after($after);
        });

        foreach (DB::table('lookups')->where('type', 'collateral_type')->pluck('id', 'code') as $code => $id) {
            DB::table($table)->where('collateral_type_id', $id)->update([$column => $code]);
        }

        if ($unique) {
            Schema::table($table, fn (Blueprint $t) => $t->index($unique, "{$table}_swap_index"));
        }

        Schema::table($table, function (Blueprint $t) use ($unique) {
            $t->dropForeign(['collateral_type_id']);
            if ($unique) {
                $t->dropUnique([...$unique, 'collateral_type_id']);
            }
            $t->dropColumn('collateral_type_id');
        });

        Schema::table($table, function (Blueprint $t) use ($column, $unique) {
            $t->string($column, 30)->nullable(false)->change();
            if ($unique) {
                $t->unique([...$unique, $column]);
            }
        });

        if ($unique) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex("{$table}_swap_index"));
        }
    }
};
