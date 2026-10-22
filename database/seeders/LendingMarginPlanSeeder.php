<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LendingMarginPlan;
use Illuminate\Database\Seeder;

/**
 * The lending-margin plans the first CrediX system was using (its lending_mortgages table), so the
 * business starts with the plans it knows. Created once: a plan head office has since changed
 * (another percentage, deactivated) is never overwritten by a later run.
 */
class LendingMarginPlanSeeder extends Seeder
{
    /**
     * code => [collateral type, name, percentage]
     */
    public const PLANS = [
        'CDP-INV-001' => ['cdp_investment', 'Investment Plan 1', '50.00'],
        'CDP-INV-002' => ['cdp_investment', 'Investment Plan 2', '60.00'],
        'CDP-INV-003' => ['cdp_investment', 'Investment Plan 3', '70.00'],
        'CDP-PRO-001' => ['property_mortgage', 'Property Mortgage Plan 1', '70.00'],
        'CDP-PRO-002' => ['property_mortgage', 'Property Mortgage Plan 2', '50.00'],
        'CDP-PRO-003' => ['property_mortgage', 'Property Mortgage Plan 3', '60.00'],
        'CDP-VEC-001' => ['vehicle', 'Vehicle Plan 1', '70.00'],
        'CDP-VEC-002' => ['vehicle', 'Vehicle Plan 2', '50.00'],
        'CDP-VEC-003' => ['vehicle', 'Vehicle Plan 3', '60.00'],
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::PLANS as $code => [$type, $name, $percentage]) {
            $order += 10;

            if (LendingMarginPlan::where('code', $code)->exists()) {
                continue;
            }

            $plan = new LendingMarginPlan(['collateral_type' => $type, 'name' => $name, 'percentage' => $percentage, 'sort_order' => $order, 'is_active' => true]);
            $plan->forceFill(['code' => $code])->save();
        }
    }
}
