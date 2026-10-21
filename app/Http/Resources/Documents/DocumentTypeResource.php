<?php

namespace App\Http\Resources\Documents;

use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentType
 */
class DocumentTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'target' => $this->target->value,
            'validity_days' => $this->validity_days,
            'allowed_mimes' => explode(',', $this->allowed_mimes),
            'max_size_kb' => $this->max_size_kb,
            'is_active' => $this->is_active,
        ];
    }
}
