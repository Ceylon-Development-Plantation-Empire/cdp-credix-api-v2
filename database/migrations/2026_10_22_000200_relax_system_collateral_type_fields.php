<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision (22 Oct 2026): nothing about a pledged item is mandatory except what identifies it.
 * The built-in collateral types keep their fields, but only the identifying one stays required:
 * the deed number (property), the registration number (vehicle) and the account / policy number
 * (deposit, CDP investment). Gold keeps its weight and karat, which the valuation needs
 * (App\Enums\CollateralValuation::engineFields), and the item description becomes optional.
 *
 * Only a type whose fields are still exactly as first seeded is changed: if head office has
 * already edited a type, their definition wins and is left alone.
 */
return new class extends Migration
{
    /**
     * code => [fields as first seeded (2026_10_22_000100), fields from now on]
     */
    private const FIELDS = [
        'property_mortgage' => [
            ['deed_no' => ['required', 'string', 'max:50'], 'deed_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'notary' => ['nullable', 'string', 'max:150'], 'plan_no' => ['nullable', 'string', 'max:50'], 'lot_no' => ['nullable', 'string', 'max:20'], 'extent' => ['required', 'string', 'max:50'], 'address' => ['required', 'string', 'max:255']],
            ['deed_no' => ['required', 'string', 'max:50'], 'deed_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'notary' => ['nullable', 'string', 'max:150'], 'plan_no' => ['nullable', 'string', 'max:50'], 'lot_no' => ['nullable', 'string', 'max:20'], 'extent' => ['nullable', 'string', 'max:50'], 'address' => ['nullable', 'string', 'max:255']],
        ],
        'vehicle' => [
            ['registration_no' => ['required', 'string', 'max:20'], 'chassis_no' => ['required', 'string', 'max:40'], 'engine_no' => ['required', 'string', 'max:40'], 'make' => ['required', 'string', 'max:50'], 'model' => ['required', 'string', 'max:50'], 'year' => ['required', 'integer', 'min:1950', 'max:next_year']],
            ['registration_no' => ['required', 'string', 'max:20'], 'chassis_no' => ['nullable', 'string', 'max:40'], 'engine_no' => ['nullable', 'string', 'max:40'], 'make' => ['nullable', 'string', 'max:50'], 'model' => ['nullable', 'string', 'max:50'], 'year' => ['nullable', 'integer', 'min:1950', 'max:next_year']],
        ],
        'gold' => [
            ['item' => ['required', 'string', 'max:100']],
            ['item' => ['nullable', 'string', 'max:100']],
        ],
        'fixed_deposit' => [
            ['account_no' => ['required', 'string', 'max:40'], 'institution' => ['required', 'string', 'max:150'], 'maturity_date' => ['nullable', 'date_format:Y-m-d']],
            ['account_no' => ['required', 'string', 'max:40'], 'institution' => ['nullable', 'string', 'max:150'], 'maturity_date' => ['nullable', 'date_format:Y-m-d']],
        ],
        'cdp_investment' => [
            ['account_no' => ['required', 'string', 'max:40'], 'institution' => ['required', 'string', 'max:150'], 'maturity_date' => ['nullable', 'date_format:Y-m-d']],
            ['account_no' => ['required', 'string', 'max:40'], 'institution' => ['nullable', 'string', 'max:150'], 'maturity_date' => ['nullable', 'date_format:Y-m-d']],
        ],
    ];

    public function up(): void
    {
        $this->swap(from: 0, to: 1);
    }

    public function down(): void
    {
        $this->swap(from: 1, to: 0);
    }

    private function swap(int $from, int $to): void
    {
        foreach (self::FIELDS as $code => $versions) {
            $row = DB::table('lookups')->where('type', 'collateral_type')->where('code', $code)->first();
            if (! $row) {
                continue;
            }
            $meta = json_decode((string) $row->meta, true) ?: [];
            if (($meta['fields'] ?? null) != $versions[$from]) {
                continue;
            }
            $meta['fields'] = $versions[$to];
            DB::table('lookups')->where('id', $row->id)->update(['meta' => json_encode($meta), 'updated_at' => now()]);
        }

        // Written directly, so the cached copy of the list is dropped (App\Support\Lookups\LookupRegistry).
        Cache::forget('lookups:v2:collateral_type');
    }
};
