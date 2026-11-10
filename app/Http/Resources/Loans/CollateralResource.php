<?php

declare(strict_types=1);

namespace App\Http\Resources\Loans;

use App\Http\Resources\Documents\DocumentResource;
use App\Models\Collateral;
use App\Support\Collateral\CollateralTypes;
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
            'type' => $this->type,
            'type_label' => app(CollateralTypes::class)->label($this->type),
            'description' => $this->description,
            'details' => $this->details,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'public_id' => $this->owner->public_id,
                'full_name' => $this->owner->full_name,
            ] : null),
            'market_value' => (string) $this->market_value,
            'forced_sale_value' => $this->forced_sale_value !== null ? (string) $this->forced_sale_value : null,
            // The lending % this item was valued at (plan or product rule), fixed when it was added.
            'ltv_percent' => (string) $this->ltv_percent,
            'eligible_amount' => (string) $this->eligible_amount,
            'valued_on' => $this->valued_on->toDateString(),
            'valued_by' => $this->valued_by,
            'status' => $this->status->value,
            'registration_ref' => $this->registration_ref,
            'custody_ref' => $this->custody_ref,
            'verified_at' => $this->verified_at?->toApiString(),
            'perfected_at' => $this->perfected_at?->toApiString(),
            'released_at' => $this->released_at?->toApiString(),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
        ];
    }
}
