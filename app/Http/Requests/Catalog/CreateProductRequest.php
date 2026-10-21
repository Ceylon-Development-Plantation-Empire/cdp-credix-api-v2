<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Catalog\Concerns\ProductFieldRules;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

class CreateProductRequest extends FormRequest
{
    use FormatsValidationErrors, ProductFieldRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->productRules('required'),
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:products,slug'],
            'charges' => ['sometimes', 'array', 'max:20'],
            ...$this->chargeRules('charges'),
            'collateral_rules' => ['sometimes', 'array', 'max:10'],
            ...$this->collateralRuleRules('collateral_rules'),
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers and single dashes.',
        ];
    }
}
