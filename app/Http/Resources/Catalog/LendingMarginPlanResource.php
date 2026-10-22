<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Models\LendingMarginPlan;
use App\Support\Collateral\CollateralTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LendingMarginPlan
 */
class LendingMarginPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'collateral_type' => $this->collateral_type,
            'collateral_type_label' => app(CollateralTypes::class)->label($this->collateral_type),
            'percentage' => (string) $this->percentage,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at?->toApiString(),
        ];
    }
}
