<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters of a point-in-time report or dashboard (portfolio, arrears aging, recovery, overview):
 * an optional branch, never a period.
 *
 * Validation errors keep the documented {message, errors: {field: [...]}} shape.
 */
class SnapshotReportRequest extends FormRequest
{
    /**
     * Permissions are checked by the controller middleware (Report View / Dashboard View).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'from' => ['prohibited'],
            'to' => ['prohibited'],
        ];
    }
}
