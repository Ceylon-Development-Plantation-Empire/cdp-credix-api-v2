<?php

declare(strict_types=1);

namespace App\Http\Resources\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Template
 */
class TemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'channel' => $this->channel->value,
            'language' => $this->language->value,
            'product' => $this->whenLoaded('product', fn () => $this->product ? ['slug' => $this->product->slug, 'name' => $this->product->name] : null),
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['slug' => $this->category->slug, 'name' => $this->category->name] : null),
            'subject' => $this->subject,
            'body' => $this->body,
            'placeholders' => NotificationEvent::tryFrom($this->slug)?->placeholders() ?? [],
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at?->toApiString(),
        ];
    }
}
