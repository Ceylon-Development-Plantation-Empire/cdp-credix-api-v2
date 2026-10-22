<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /loans/{publicId}/renewal-quote: what renewing a gold loan costs today, for an optional new tenure.
 *
 * Validation errors keep the documented {message, errors: {field: [...]}} shape.
 */
class RenewalQuoteRequest extends FormRequest
{
    /**
     * Permission "Gold Loan Renew" is checked by the controller middleware.
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
            'tenure' => ['nullable', 'integer', 'min:1', 'max:120'],
        ];
    }
}
