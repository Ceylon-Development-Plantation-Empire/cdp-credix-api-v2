<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /gl/trial-balance filters.
 *
 * Validation errors keep the documented {message, errors: {field: [...]}} shape.
 */
class TrialBalanceRequest extends FormRequest
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
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'include_zero' => ['sometimes', 'boolean'],
        ];
    }
}
