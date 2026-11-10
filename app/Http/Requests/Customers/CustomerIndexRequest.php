<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /customers: list filters and the whitelisted sort orders.
 *
 * Validation errors keep the documented {message, errors: {field: [...]}} shape.
 */
class CustomerIndexRequest extends FormRequest
{
    /**
     * Permission "Customer Index" is checked by the controller middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('allow_multiple_loans')) {
            $this->merge([
                'allow_multiple_loans' => $this->boolean('allow_multiple_loans'),
            ]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'sort' => ['nullable', Rule::in(['newest', 'name', 'credit_score', '-credit_score'])],
            'search' => ['nullable', 'string', 'max:100'],
            'allow_multiple_loans' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
