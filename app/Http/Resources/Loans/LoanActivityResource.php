<?php

declare(strict_types=1);

namespace App\Http\Resources\Loans;

use App\Models\LoanActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanActivity
 */
class LoanActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status?->value,
            'remarks' => $this->remarks,
            'performed_by' => $this->whenLoaded('performer', fn () => $this->performer ? ['id' => $this->performer->id, 'name' => $this->performer->name] : null),
            'performed_at' => $this->performed_at->toApiString(),
        ];
    }
}
