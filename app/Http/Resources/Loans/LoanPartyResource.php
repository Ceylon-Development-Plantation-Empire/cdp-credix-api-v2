<?php

namespace App\Http\Resources\Loans;

use App\Models\LoanParty;
use App\Support\Mask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanParty
 */
class LoanPartyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'customer' => $this->whenLoaded('customer', fn () => [
                'public_id' => $this->customer->public_id,
                'customer_no' => $this->customer->customer_no,
                'full_name' => $this->customer->full_name,
                'id_number' => Mask::idNumber($this->customer->id_number_normalized),
            ]),
            'relationship' => $this->relationship,
            'consented_at' => $this->consented_at?->toIso8601String(),
            'invoked_at' => $this->invoked_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
        ];
    }
}
