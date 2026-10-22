<?php

declare(strict_types=1);

namespace App\Http\Resources\Documents;

use App\Models\DocumentRequirement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentRequirement
 */
class DocumentRequirementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => $this->product ? ['slug' => $this->product->slug, 'name' => $this->product->name] : null,
            'party_role' => $this->party_role->value,
            'employment_status' => $this->employment_status?->value,
            'requirement_group' => $this->requirement_group,
            'rule' => $this->rule->value,
            'document_type' => ['slug' => $this->documentType->slug, 'name' => $this->documentType->name],
            'min_count' => $this->min_count,
            'is_mandatory' => $this->is_mandatory,
        ];
    }
}
