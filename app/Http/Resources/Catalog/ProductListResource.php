<?php

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
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'category' => ['slug' => $this->category->slug, 'name' => $this->category->name],
            'finance_basis' => $this->category->finance_basis->value,
            'pricing_method' => $this->pricing_method->value,
            'default_rate' => $this->default_rate,
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,
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
