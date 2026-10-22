<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the product list: only what the table shows.
 *
 * @mixin Product
 */
class ProductListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $primaryCharge = $this->whenLoaded('charges', fn () => $this->charges->firstWhere('charge_type', 'processing_fee') ?? $this->charges->first());

        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'category' => ['slug' => $this->category->slug, 'name' => $this->category->name],
            'finance_basis' => $this->category->finance_basis->value,
            'pricing_method' => $this->pricing_method->value,
            'default_rate' => $this->default_rate,
            'interest_rate' => (float) $this->default_rate,
            'min_rate' => $this->min_rate,
            'max_rate' => $this->max_rate,
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,
            'min_tenure' => $this->min_tenure,
            'max_tenure' => $this->max_tenure,
            'min_term_months' => $this->min_tenure,
            'max_term_months' => $this->max_tenure,
            'processing_fee_type' => $primaryCharge ? ($primaryCharge->calculation === 'fixed' ? 'fixed' : 'percentage') : null,
            'processing_fee_value' => $primaryCharge ? (float) $primaryCharge->value : null,
            'repayment_frequency' => $this->repayment_frequency->value,
            'borrower_types' => array_keys(array_filter([
                'individual' => $this->allows_individual,
                'joint' => $this->allows_joint,
                'group' => $this->allows_group,
            ])),
            'status' => $this->status->value,
        ];
    }
}
