<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Catalog\Concerns\ProductFieldRules;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    use FormatsValidationErrors, ProductFieldRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Charges and collateral rules have their own endpoints (PUT /products/{slug}/charges …),
     * so they are not accepted here.
     */
    public function rules(): array
    {
        $product = Product::withTrashed()->where('slug', $this->route('slug'))->first();

        return [
            ...$this->productRules('sometimes'),
            'slug' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($product?->id)],
            // Needed when the change touches Shariah-approved terms and goes to the board.
            'change_reason' => ['nullable', 'string', 'min:5', 'max:1000'],
            'charges' => ['prohibited'],
            'collateral_rules' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers and single dashes.',
            'charges.prohibited' => 'Charges are changed through PUT /products/{slug}/charges.',
            'collateral_rules.prohibited' => 'Collateral rules are changed through PUT /products/{slug}/collateral-rules.',
        ];
    }
}
