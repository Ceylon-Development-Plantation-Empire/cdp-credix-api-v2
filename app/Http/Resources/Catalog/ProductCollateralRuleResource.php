<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Models\ProductCollateralRule;
use App\Support\Collateral\CollateralTypes;
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
            'collateral_type' => $this->collateral_type,
            'label' => app(CollateralTypes::class)->label($this->collateral_type),
            'max_ltv_percent' => $this->max_ltv_percent,
            'is_mandatory' => $this->is_mandatory,
        ];
    }
}
