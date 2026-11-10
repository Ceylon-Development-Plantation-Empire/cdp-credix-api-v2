<?php

declare(strict_types=1);

namespace App\Http\Resources\Groups;

use App\Models\BorrowerGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BorrowerGroup
 */
class BorrowerGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'group_no' => $this->group_no,
            'name' => $this->name,
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'formed_on' => $this->formed_on->toDateString(),
            'meeting_day' => $this->meeting_day,
            'status' => $this->status->value,
            'members_count' => $this->whenCounted('currentMembers'),
            'members' => GroupMemberResource::collection($this->whenLoaded('currentMembers')),
        ];
    }
}
