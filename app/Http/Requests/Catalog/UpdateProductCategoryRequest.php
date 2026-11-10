<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Enums\FinanceBasis;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductCategoryRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $category = ProductCategory::findBySlug((string) $this->route('slug'));

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('product_categories', 'slug')->ignore($category?->id)],
            // Send null to keep a root as root; moving is checked for loops and basis by the service.
            'parent_slug' => ['sometimes', 'nullable', 'string', Rule::exists('product_categories', 'slug')],
            'finance_basis' => ['sometimes', Rule::enum(FinanceBasis::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
