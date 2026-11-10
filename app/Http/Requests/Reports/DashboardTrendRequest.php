<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

/**
 * GET /dashboard/trend: a snapshot report plus ?months=1..12 (default 6).
 */
class DashboardTrendRequest extends SnapshotReportRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'months' => ['nullable', 'integer', 'min:1', 'max:12'],
        ];
    }
}
