<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The reference rows the code itself depends on: lookup values (including the system collateral
 * types), charge types (with the system engine types), the starting lending-margin plans and the
 * system GL accounts. Without them postings and lookup-backed fields fail,
 * so this runs in every environment (through SystemDataSeeder) and before every test
 * (Tests\TestCase).
 */
class ReferenceDataSeeder extends Seeder
{
    /**
     * Seed the reference data.
     */
    public function run(): void
    {
        $this->call([
            LookupSeeder::class,
            ChargeTypeSeeder::class,
            ChartOfAccountsSeeder::class,
        ]);
    }
}
