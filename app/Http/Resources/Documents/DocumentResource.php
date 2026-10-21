<?php

namespace App\Http\Resources\Documents;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The storage path is never returned; files are fetched with GET /documents/{public_id}/download.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'document_type' => ['slug' => $this->documentType->slug, 'name' => $this->documentType->name],
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'issued_on' => $this->issued_on?->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'remarks' => $this->remarks,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader ? ['name' => $this->uploader->name] : null),
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'verified_by' => $this->whenLoaded('verifier', fn () => $this->verifier ? ['name' => $this->verifier->name] : null),
            'verified_at' => $this->verified_at?->toIso8601String(),
        ];
    }
}
