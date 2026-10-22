<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /gl/reconciliation: the GL against the loan sub-ledger, optionally for one branch.
 *
 * Validation errors keep the documented {message, errors: {field: [...]}} shape.
 */
class ReconciliationRequest extends FormRequest
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
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }
}
