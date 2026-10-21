<?php

namespace App\Http\Resources\Groups;

use App\Models\BorrowerGroupMember;
use App\Support\Mask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BorrowerGroupMember
 */
class GroupMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => $this->whenLoaded('customer', fn () => [
                'public_id' => $this->customer->public_id,
                'customer_no' => $this->customer->customer_no,
                'full_name' => $this->customer->full_name,
                'id_number' => Mask::idNumber($this->customer->id_number_normalized),
            ]),
            'role' => $this->role->value,
            'joined_on' => $this->joined_on->toDateString(),
            'left_on' => $this->left_on?->toDateString(),
        ];
    }
}
