<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Catalog\Concerns\ProductFieldRules;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceProductChargesRequest extends FormRequest
{
    use FormatsValidationErrors, ProductFieldRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The full list of charges; anything not in the list is removed. An empty list removes all charges.
     */
    public function rules(): array
    {
        return [
            'charges' => ['present', 'array', 'max:20'],
            ...$this->chargeRules('charges'),
            'change_reason' => ['nullable', 'string', 'min:5', 'max:1000'],
        ];
    }
}
