<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\LendingMarginPlan;
use App\Models\User;
use App\Traits\ActivityLogTrait;
use Illuminate\Support\Str;

/**
 * Lending-margin plans: named lending percentages per collateral type, managed by head office.
 *
 * Field-level changes (old → new percentage, name …) are recorded by the model's audit trail; this
 * service adds the short business entry.
 */
class LendingMarginPlanService
{
    use ActivityLogTrait;

    /**
     * @param  array<string, mixed>  $data  Validated StoreLendingMarginPlanRequest data.
     */
    public function create(array $data, User $by): LendingMarginPlan
    {
        $plan = new LendingMarginPlan($data + ['is_active' => true]);
        $plan->forceFill([
            'code' => $data['code'] ?? $this->codeFor($data['name']),
            'created_by' => $by->id,
            'updated_by' => $by->id,
        ])->save();

        $this->logActivity('CREATE', 'LendingMarginPlan', "Added lending-margin plan {$plan->code} ({$plan->collateral_type}, {$plan->percentage}%)");

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $data  Validated UpdateLendingMarginPlanRequest data.
     */
    public function update(LendingMarginPlan $plan, array $data, User $by): LendingMarginPlan
    {
        $plan->fill($data)->forceFill(['updated_by' => $by->id])->save();

        $this->logActivity('UPDATE', 'LendingMarginPlan', "Changed lending-margin plan {$plan->code} ({$plan->percentage}%)");

        return $plan;
    }

    /**
     * A stable code from the name ("Property Mortgage Plan 4" → PROPERTY-MORTGAGE-PLAN-4), with a
     * number added when it is already taken.
     */
    protected function codeFor(string $name): string
    {
        $base = Str::upper(Str::limit(Str::slug($name), 34, '')) ?: 'PLAN';
        $code = $base;

        for ($n = 2; LendingMarginPlan::where('code', $code)->exists(); $n++) {
            $code = "{$base}-{$n}";
        }

        return $code;
    }
}
