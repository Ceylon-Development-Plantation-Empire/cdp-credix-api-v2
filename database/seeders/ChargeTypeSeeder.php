<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ChargeBehaviour;
use App\Models\ChargeType;
use Illuminate\Database\Seeder;

/**
 * System reference data: the starting fee types.
 *
 * The three engine-raised types (late payment, early settlement, safekeeping) are system rows: the
 * servicing engine finds them by behaviour, so they are never deactivated. The upfront fees are
 * ordinary data the business may rename, regroup, deactivate or extend.
 *
 * Idempotent: firstOrCreate on the code never overwrites a type someone has since edited.
 */
class ChargeTypeSeeder extends Seeder
{
    /**
     * Combined line on quotes and statements for the fees below (owner decision: one name with a breakdown).
     */
    public const SERVICE_CHARGES = 'Service charges';

    /**
     * Seed the types.
     */
    public function run(): void
    {
        $types = [
            // code, name, display group, behaviour, system
            ['processing_fee', 'Processing fee', self::SERVICE_CHARGES, ChargeBehaviour::Upfront, false],
            ['documentation_fee', 'Documentation fee', self::SERVICE_CHARGES, ChargeBehaviour::Upfront, false],
            ['insurance', 'Insurance', self::SERVICE_CHARGES, ChargeBehaviour::Upfront, false],
            ['stamp_duty', 'Stamp duty', self::SERVICE_CHARGES, ChargeBehaviour::Upfront, false],
            ['valuation_fee', 'Valuation fee', self::SERVICE_CHARGES, ChargeBehaviour::Upfront, false],
            ['late_payment', 'Late payment charge', 'Late payment charges', ChargeBehaviour::LatePayment, true],
            ['early_settlement', 'Early settlement fee', 'Early settlement fee', ChargeBehaviour::EarlySettlement, true],
            ['safekeeping_fee', 'Safekeeping fee', 'Safekeeping fee', ChargeBehaviour::Safekeeping, true],
        ];

        $order = 0;
        foreach ($types as [$code, $name, $group, $behaviour, $system]) {
            $type = ChargeType::firstOrCreate(['code' => $code], [
                'name' => $name,
                'display_group' => $group,
                'behaviour' => $behaviour,
                'allowed_calculations' => array_map(fn ($c) => $c->value, $behaviour->permittedCalculations()),
                // Insurance may be charged with every installment; the other upfront fees once.
                'allowed_recurrences' => $code === 'insurance' || $behaviour !== ChargeBehaviour::Upfront
                    ? array_map(fn ($r) => $r->value, $behaviour->permittedRecurrences())
                    : ['once'],
                'is_active' => true,
                'sort_order' => $order += 10,
            ]);

            // is_system is not mass assignable; only this seeder sets it.
            if ($system && ! $type->is_system) {
                $type->forceFill(['is_system' => true])->save();
            }
        }
    }
}
