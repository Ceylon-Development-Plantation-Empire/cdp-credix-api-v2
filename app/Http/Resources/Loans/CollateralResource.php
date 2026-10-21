<?php

namespace App\Http\Resources\Loans;

use App\Models\Collateral;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Collateral
 */
class CollateralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'description' => $this->description,
            'details' => $this->details,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'public_id' => $this->owner->public_id,
                'full_name' => $this->owner->full_name,
            ] : null),
            'market_value' => (string) $this->market_value,
            'forced_sale_value' => $this->forced_sale_value !== null ? (string) $this->forced_sale_value : null,
            'ltv_percent' => (string) $this->ltv_percent,
            'eligible_amount' => (string) $this->eligible_amount,
            'valued_on' => $this->valued_on->toDateString(),
            'valued_by' => $this->valued_by,
            'status' => $this->status->value,
            'registration_ref' => $this->registration_ref,
            'custody_ref' => $this->custody_ref,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'perfected_at' => $this->perfected_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
        ];
    }
}
