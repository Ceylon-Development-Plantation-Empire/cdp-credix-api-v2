<?php

declare(strict_types=1);

namespace App\Http\Resources\Customers;

use App\Models\CustomerFinancial;
use Illuminate\Http\Request;

/**
 * A financial line with the customer it belongs to, for lists across customers.
 *
 * @mixin CustomerFinancial
 */
class CustomerFinancialListResource extends CustomerFinancialResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'customer' => [
                'public_id' => $this->customer->public_id,
                'customer_no' => $this->customer->customer_no,
                'full_name' => $this->customer->full_name,
            ],
            'created_at' => $this->created_at?->toApiString(),
        ];
    }
}
