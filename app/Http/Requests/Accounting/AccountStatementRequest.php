<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /gl/accounts/{id}/statement filters (this month by default, at most one year).
 *
 * Validation errors keep the documented {message, errors: {field: [...]}} shape.
 */
class AccountStatementRequest extends FormRequest
{
    /**
     * Permission "GL Report View" is checked by the controller middleware.
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
