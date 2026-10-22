<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Support\BusinessDate;

/**
 * GET /dashboard/overview: an optional branch and an optional period (this month so far by default).
 * Without a period the headline figures are the month to date, as before.
 */
class DashboardOverviewRequest extends SnapshotReportRequest
{
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
