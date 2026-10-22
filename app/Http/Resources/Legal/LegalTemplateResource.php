<?php

declare(strict_types=1);

namespace App\Http\Resources\Legal;

use App\Enums\LegalDocumentType;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wording of a legal document.
 *
 * @mixin Template
 */
class LegalTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = LegalDocumentType::tryFrom($this->slug);

        return [
            'id' => $this->id,
            'document_type' => $this->slug,
            'document_type_label' => $type?->label(),
            'language' => $this->language->value,
            'product' => $this->whenLoaded('product', fn () => $this->product ? ['slug' => $this->product->slug, 'name' => $this->product->name] : null),
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['slug' => $this->category->slug, 'name' => $this->category->name] : null),
            'title' => $this->subject ?? $type?->label(),
            'content' => $this->body,
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at?->toApiString(),
        ];
    }
}
