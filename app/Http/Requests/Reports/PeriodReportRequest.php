<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters of a period report (collections, disbursements, branch performance): an optional
 * branch and an optional from / to (this month by default; at most one year, checked by the
 * controller as REPORT_PERIOD_TOO_LONG).
 */
class PeriodReportRequest extends FormRequest
{
    /**
     * Permission "Report View" is checked by the controller middleware.
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.BusinessDate::today()->toDateString()],
        ];
    }
}
