<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\DashboardOverviewRequest;
use App\Http\Requests\Reports\DashboardTrendRequest;
use App\Http\Requests\Reports\PeriodReportRequest;
use App\Http\Requests\Reports\SnapshotReportRequest;
use App\Services\Reports\DashboardService;
use App\Services\Reports\ReportService;
use App\Support\BusinessDate;
use App\Traits\HandlesApiErrors;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Management reports and the dashboard. Figures follow the user's branch access: a branch user
 * sees their branch; head office sees every branch or picks one with ?branch_id=.
 */
class ReportController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    /**
     * Longest period one report may cover (days).
     */
    private const MAX_PERIOD_DAYS = 366;

    public function __construct(
        protected ReportService $reports,
        protected DashboardService $dashboard,
    ) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Report View', except: ['overview', 'trend']),
            new Middleware('permission:Dashboard View', only: ['overview', 'trend']),
        ];
    }

    /**
     * GET /dashboard/overview?from&to&branch_id: headline figures, KPIs against the previous period,
     * targets, pipeline, portfolio mix, arrears aging, recovery, upcoming dues, trend and recent activity.
     */
    public function overview(DashboardOverviewRequest $request): JsonResponse
    {
        return $this->respond('Dashboard', fn () => $this->dashboard->overview($request->validated()));
    }

    /**
     * GET /dashboard/trend: paid out and collected per month for the dashboard chart.
     */
    public function trend(DashboardTrendRequest $request): JsonResponse
    {
        $filters = $request->safe()->except('months');
        $months = (int) ($request->validated('months') ?? 6);

        return $this->respond('Dashboard trend', fn () => $this->reports->monthlyTrend($months, $filters));
    }

    /**
     * GET /reports/portfolio.
     */
    public function portfolio(SnapshotReportRequest $request): JsonResponse
    {
        return $this->respond('Portfolio report', fn () => $this->reports->portfolio($request->validated()));
    }

    /**
     * GET /reports/arrears-aging.
     */
    public function arrearsAging(SnapshotReportRequest $request): JsonResponse
    {
        return $this->respond('Arrears aging report', fn () => $this->reports->arrearsAging($request->validated()));
    }

    /**
     * GET /reports/collections.
     */
    public function collections(PeriodReportRequest $request): JsonResponse
    {
        return $this->respond('Collections report', fn () => $this->reports->collections($this->period($request)));
    }

    /**
     * GET /reports/disbursements.
     */
    public function disbursements(PeriodReportRequest $request): JsonResponse
    {
        return $this->respond('Disbursements report', fn () => $this->reports->disbursements($this->period($request)));
    }

    /**
     * GET /reports/branch-performance.
     */
    public function branchPerformance(PeriodReportRequest $request): JsonResponse
    {
        return $this->respond('Branch performance report', fn () => $this->reports->branchPerformance($this->period($request)));
    }

    /**
     * GET /reports/recovery.
     */
    public function recovery(SnapshotReportRequest $request): JsonResponse
    {
        return $this->respond('Recovery report', fn () => $this->reports->recovery($request->validated()));
    }

    /**
     * Period filters with this month as the default, never longer than a year.
     *
     * @return array{branch_id?: int|null, from: string, to: string}
     *
     * @throws BusinessRuleException REPORT_PERIOD_TOO_LONG
     */
    protected function period(PeriodReportRequest $request): array
    {
        $filters = $request->validated();
        $filters['from'] ??= BusinessDate::today()->startOfMonth()->toDateString();
        $filters['to'] ??= BusinessDate::today()->toDateString();

        if (CarbonImmutable::parse($filters['from'])->diffInDays(CarbonImmutable::parse($filters['to'])) > self::MAX_PERIOD_DAYS) {
            throw new BusinessRuleException('REPORT_PERIOD_TOO_LONG', 'A report covers at most one year.', 422);
        }

        return $filters;
    }

    /**
     * The documented success envelope around a report.
     *
     * @param  callable(): mixed  $report
     */
    protected function respond(string $name, callable $report): JsonResponse
    {
        try {
            return response()->json([
                'status' => 'success',
                'message' => "{$name} generated",
                'data' => $report(),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError("Failed to generate {$name}", $th);
        }
    }
}
