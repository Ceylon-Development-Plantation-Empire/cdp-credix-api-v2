<?php

namespace App\Http\Resources\Customers;

use App\Models\CustomerFinancial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerFinancial
 */
class CustomerFinancialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'category' => $this->category,
            'description' => $this->description,
            'institution_name' => $this->institution_name,
            'reference_no' => $this->reference_no,
            'amount' => $this->amount,
            'monthly_obligation' => $this->monthly_obligation,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_verified' => $this->is_verified,
            'verified_at' => $this->verified_at?->toIso8601String(),
        ];
    }
}
