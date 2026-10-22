<?php

declare(strict_types=1);

namespace App\Http\Requests\Legal;

use App\Enums\Language;
use App\Enums\LegalDocumentType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The wording of a legal document: its type, language and (optionally) the product or category it is for.
 */
class LegalTemplateRequest extends FormRequest
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
            'document_type' => $creating ? ['required', Rule::enum(LegalDocumentType::class)] : ['prohibited'],
            'language' => $creating ? ['required', Rule::enum(Language::class)] : ['prohibited'],
            'product_slug' => $creating ? ['nullable', 'string', Rule::exists('products', 'slug')->whereNull('deleted_at'), 'prohibits:category_slug'] : ['prohibited'],
            'category_slug' => $creating ? ['nullable', 'string', Rule::exists('product_categories', 'slug')] : ['prohibited'],
            'title' => ['nullable', 'string', 'max:150'],
            'content' => [$creating ? 'required' : 'sometimes', 'string', 'min:20', 'max:300000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
