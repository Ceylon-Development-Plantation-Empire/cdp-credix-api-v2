<?php

namespace App\Http\Resources\Catalog;

use App\Models\ProductCollateralRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductCollateralRule
 */
class ProductCollateralRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'collateral_type' => $this->collateral_type->value,
            'label' => $this->collateral_type->label(),
            'max_ltv_percent' => $this->max_ltv_percent,
            'is_mandatory' => $this->is_mandatory,
        ];
    }
}
