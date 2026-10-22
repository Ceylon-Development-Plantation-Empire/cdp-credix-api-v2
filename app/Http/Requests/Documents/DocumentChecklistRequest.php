<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Enums\PartyRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /customers/{publicId}/document-checklist: the documents a person still needs for a role and product.
 *
 * Validation errors keep the documented {message, errors: {field: [...]}} shape.
 */
class DocumentChecklistRequest extends FormRequest
{
    /**
     * Permission "Document Index" is checked by the controller middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::enum(PartyRole::class)],
            'product_slug' => ['nullable', 'string', Rule::exists('products', 'slug')],
        ];
    }
}
