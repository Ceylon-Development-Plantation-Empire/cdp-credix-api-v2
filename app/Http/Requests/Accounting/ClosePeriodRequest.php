<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /gl/period/close: close the books through a date (no posting may be dated on or before it afterwards).
 *
 * Validation errors keep the documented {message, errors: {field: [...]}} shape.
 */
class ClosePeriodRequest extends FormRequest
{
    /**
     * Permission "GL Period Close" is checked by the controller middleware.
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
            'through' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
