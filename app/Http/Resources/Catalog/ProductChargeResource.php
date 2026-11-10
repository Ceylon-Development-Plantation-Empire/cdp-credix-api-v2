<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Models\ProductCharge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product's fee or charge, with the name and display group of its charge type (so screens can
 * show "Service charges" with the itemised breakdown).
 *
 * @mixin ProductCharge
 */
class ProductChargeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->type();

        return [
            'charge_type' => $type->code,
            'name' => $type->name,
            'display_group' => $type->display_group,
            'behaviour' => $type->behaviour->value,
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
