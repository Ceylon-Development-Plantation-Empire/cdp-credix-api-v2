<?php

namespace App\Http\Resources\Catalog;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full product detail for the product screen and the loan wizard.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $basis = $this->category->finance_basis;
        $pending = $this->pendingApproval();

        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'category' => ['slug' => $this->category->slug, 'name' => $this->category->name],
            'finance_basis' => $basis->value,
            // "Interest" or "Profit" – what the frontend should call the cost of finance for this product.
            'cost_label' => $basis->costLabel(),
            'counterpart' => $this->whenLoaded('counterpart', fn () => $this->counterpart ? [
                'slug' => $this->counterpart->slug,
                'name' => $this->counterpart->name,
            ] : null),
            'pricing_method' => ['value' => $this->pricing_method->value, 'label' => $this->pricing_method->label()],
            'rates' => [
                'default' => $this->default_rate,
                'min' => $this->min_rate,
                'max' => $this->max_rate,
            ],
            'amount' => ['min' => $this->min_amount, 'max' => $this->max_amount],
            'tenure' => ['min' => $this->min_tenure, 'max' => $this->max_tenure],
            'repayment_frequency' => $this->repayment_frequency->value,
            'borrower_types' => [
                'individual' => $this->allows_individual,
                'joint' => $this->allows_joint,
                'group' => $this->allows_group,
            ],
            'group_members' => $this->allows_group ? ['min' => $this->min_group_members, 'max' => $this->max_group_members] : null,
            'guarantors' => ['min' => $this->min_guarantors, 'max' => $this->max_guarantors],
            'requires_collateral' => $this->requires_collateral,
            'grace_period_days' => $this->grace_period_days,
            'max_dsr_percent' => $this->max_dsr_percent,
            'residual_value_percent' => $this->residual_value_percent,
            'early_settlement' => [
                'allowed' => $this->early_settlement_allowed,
                'rebate_percent' => $this->early_settlement_rebate_percent,
            ],
            'status' => $this->status->value,
            'shariah' => $basis->requiresShariahApproval() ? [
                'approved_at' => $this->shariah_approved_at?->toIso8601String(),
                'reference' => $this->shariah_reference,
            ] : null,
            'pending_approval' => $pending ? [
                'public_id' => $pending->public_id,
                'action' => $pending->action->value,
                'requested_at' => $pending->created_at?->toIso8601String(),
            ] : null,
            'charges' => ProductChargeResource::collection($this->whenLoaded('charges')),
            'collateral_rules' => ProductCollateralRuleResource::collection($this->whenLoaded('collateralRules')),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
