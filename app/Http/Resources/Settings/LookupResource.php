<?php

declare(strict_types=1);

namespace App\Http\Resources\Settings;

use App\Models\Lookup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One value of a code list, as the admin screens see it.
 *
 * @mixin Lookup
 */
class LookupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'code' => $this->code,
            'label' => $this->label,
            'description' => $this->description,
            'meta' => $this->meta,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'is_system' => $this->is_system,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'updated_at' => $this->updated_at?->toApiString(),
        ];
    }
}
