<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /gl/journals/export: posted journal lines as CSV (one year at most per file).
 *
 * Validation errors keep the documented {message, errors: {field: [...]}} shape.
 */
class JournalExportRequest extends FormRequest
{
    /**
     * Permission "GL Journal Index" is checked by the controller middleware.
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
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }
}
