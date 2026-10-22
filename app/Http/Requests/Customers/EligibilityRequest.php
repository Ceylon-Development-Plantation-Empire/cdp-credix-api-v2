<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /customers/{publicId}/eligibility: can this person apply for a product now?
 *
 * Validation errors keep the documented {message, errors: {field: [...]}} shape.
 */
class EligibilityRequest extends FormRequest
{
    /**
     * Permission "Customer Index" is checked by the controller middleware.
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
            'product_slug' => ['required', 'string', Rule::exists('products', 'slug')->whereNull('deleted_at')],
            'tenure' => ['nullable', 'integer', 'min:1', 'max:600'],
        ];
    }
}
