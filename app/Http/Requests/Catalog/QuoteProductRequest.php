<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

class QuoteProductRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Product limits (amount, tenure, rate band) are checked by ProductService::quote against the product.
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'decimal:0,2', 'min:1', 'max:9999999999999'],
            'tenure' => ['required', 'integer', 'min:1', 'max:600'],
            'rate' => ['nullable', 'decimal:0,4', 'min:0', 'max:100'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'first_due_date' => ['nullable', 'date_format:Y-m-d', 'after:start_date'],
        ];
    }
}
