<?php

namespace App\Http\Requests\Catalog\Concerns;

use App\Enums\ChargeCalculation;
use App\Enums\ChargeCollection;
use App\Enums\ChargeRecurrence;
use App\Enums\ChargeType;
use App\Enums\CollateralType;
use App\Enums\PricingMethod;
use App\Enums\RepaymentFrequency;
use Illuminate\Validation\Rule;

/**
 * Field rules shared by product create and update, so they can never drift apart.
 *
 * These only check each field on its own. Combinations (Islamic vs method, min ≤ max on a partial
 * update, charity flags …) are checked by ProductRulesValidator against the final product.
 *
 * Money and rates are validated as decimal strings ("decimal:0,2") so they never pass through floats.
 */
trait ProductFieldRules
{
    /**
     * @param  string  $presence  'required' for create, 'sometimes' for update.
     * @return array<string, mixed>
     */
    protected function productRules(string $presence): array
    {
        return [
            'name' => [$presence, 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_slug' => [$presence, 'string', Rule::exists('product_categories', 'slug')],
            'counterpart_product_slug' => ['nullable', 'string', Rule::exists('products', 'slug')->whereNull('deleted_at')],
            'pricing_method' => [$presence, Rule::enum(PricingMethod::class)],
            'default_rate' => [$presence, 'decimal:0,4', 'min:0', 'max:100'],
            'min_rate' => [$presence, 'decimal:0,4', 'min:0', 'max:100'],
            'max_rate' => [$presence, 'decimal:0,4', 'min:0', 'max:100'],
            'min_amount' => [$presence, 'decimal:0,2', 'min:1', 'max:9999999999999'],
            'max_amount' => [$presence, 'decimal:0,2', 'min:1', 'max:9999999999999'],
            'min_tenure' => [$presence, 'integer', 'min:1', 'max:600'],
            'max_tenure' => [$presence, 'integer', 'min:1', 'max:600'],
            'repayment_frequency' => [$presence, Rule::enum(RepaymentFrequency::class)],
            'allows_individual' => ['sometimes', 'boolean'],
            'allows_joint' => ['sometimes', 'boolean'],
            'allows_group' => ['sometimes', 'boolean'],
            'min_group_members' => ['nullable', 'integer', 'min:2', 'max:100'],
            'max_group_members' => ['nullable', 'integer', 'min:2', 'max:100'],
            'min_guarantors' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'max_guarantors' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'requires_collateral' => ['sometimes', 'boolean'],
            'grace_period_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'max_dsr_percent' => ['nullable', 'decimal:0,2', 'min:1', 'max:100'],
            'residual_value_percent' => ['nullable', 'decimal:0,2', 'min:0', 'max:99'],
            'early_settlement_allowed' => ['sometimes', 'boolean'],
            'early_settlement_rebate_percent' => ['nullable', 'decimal:0,2', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function chargeRules(string $prefix): array
    {
        return [
            "{$prefix}.*.charge_type" => ['required', Rule::enum(ChargeType::class), 'distinct'],
            "{$prefix}.*.calculation" => ['required', Rule::enum(ChargeCalculation::class)],
            "{$prefix}.*.value" => ['required', 'decimal:0,4', 'min:0', 'max:99999999'],
            "{$prefix}.*.min_amount" => ['nullable', 'decimal:0,2', 'min:0'],
            "{$prefix}.*.max_amount" => ['nullable', 'decimal:0,2', 'min:0'],
            "{$prefix}.*.collection" => ['required', Rule::enum(ChargeCollection::class)],
            "{$prefix}.*.recurrence" => ['sometimes', Rule::enum(ChargeRecurrence::class)],
            "{$prefix}.*.is_charity" => ['sometimes', 'boolean'],
            "{$prefix}.*.is_active" => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function collateralRuleRules(string $prefix): array
    {
        return [
            "{$prefix}.*.collateral_type" => ['required', Rule::enum(CollateralType::class), 'distinct'],
            "{$prefix}.*.max_ltv_percent" => ['required', 'decimal:0,2', 'min:0.01', 'max:100'],
            "{$prefix}.*.is_mandatory" => ['sometimes', 'boolean'],
        ];
    }
}
