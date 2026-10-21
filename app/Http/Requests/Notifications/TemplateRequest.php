<?php

namespace App\Http\Requests\Notifications;

use App\Enums\Language;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A message text. On create the slug is an event; product_slug or category_slug make it specific
 * (only one of them). Placeholders are checked against the event's list by the controller.
 */
class TemplateRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'slug' => $creating ? ['required', Rule::enum(NotificationEvent::class)] : ['prohibited'],
            'channel' => $creating ? ['required', Rule::enum(NotificationChannel::class)] : ['prohibited'],
            'language' => $creating ? ['required', Rule::enum(Language::class)] : ['prohibited'],
            'product_slug' => $creating ? ['nullable', 'string', Rule::exists('products', 'slug')->whereNull('deleted_at'), 'prohibits:category_slug'] : ['prohibited'],
            'category_slug' => $creating ? ['nullable', 'string', Rule::exists('product_categories', 'slug')] : ['prohibited'],
            'subject' => ['nullable', 'string', 'max:150'],
            'body' => [$creating ? 'required' : 'sometimes', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
