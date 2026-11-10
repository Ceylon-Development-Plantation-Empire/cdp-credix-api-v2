<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Enums\FinanceBasis;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateProductCategoryRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * finance_basis is only needed for a root category; a sub-category always takes its parent's.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:product_categories,slug'],
            'parent_slug' => ['nullable', 'string', Rule::exists('product_categories', 'slug')],
            'finance_basis' => ['required_without:parent_slug', 'prohibits:parent_slug', Rule::enum(FinanceBasis::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers and single dashes.',
            'finance_basis.required_without' => 'A root category must say whether it is conventional or islamic.',
            'finance_basis.prohibits' => 'A sub-category takes the finance basis of its parent; do not send finance_basis with parent_slug.',
        ];
    }
}
