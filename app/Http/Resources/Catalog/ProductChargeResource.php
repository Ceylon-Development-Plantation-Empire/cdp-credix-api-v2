<?php

namespace App\Http\Resources\Catalog;

use App\Models\ProductCharge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductCharge
 */
class ProductChargeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'charge_type' => $this->charge_type->value,
            'calculation' => $this->calculation->value,
            'value' => $this->value,
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,
            'collection' => $this->collection->value,
            'recurrence' => $this->recurrence->value,
            'is_charity' => $this->is_charity,
            'is_active' => $this->is_active,
        ];
    }
}
