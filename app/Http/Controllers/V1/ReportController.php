<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Management reports and the dashboard. Figures follow the user's branch access: a branch user
 * sees their branch; head office sees every branch or picks one with ?branch_id=.
 */
class ReportController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected ReportService $reports,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Report View', except: ['overview', 'trend']),
            new Middleware('permission:Dashboard View', only: ['overview', 'trend']),
        ];
    }

    public function overview(Request $request)
    {
        return $this->run('Dashboard', fn (array $f) => $this->reports->overview($f), $request, period: false);
    }

    /**
     * Paid out and collected per month for the dashboard chart (?months=1..12, default 6).
     */
    public function trend(Request $request)
    {
        $months = $request->validate(['months' => ['nullable', 'integer', 'min:1', 'max:12']])['months'] ?? 6;

        return $this->run('Dashboard trend', fn (array $f) => $this->reports->monthlyTrend((int) $months, $f), $request, period: false);
    }

    public function portfolio(Request $request)
    {
        return $this->run('Portfolio report', fn (array $f) => $this->reports->portfolio($f), $request, period: false);
    }

    public function arrearsAging(Request $request)
    {
        return $this->run('Arrears aging report', fn (array $f) => $this->reports->arrearsAging($f), $request, period: false);
    }

    public function collections(Request $request)
    {
        return $this->run('Collections report', fn (array $f) => $this->reports->collections($f), $request, period: true);
    }

    public function disbursements(Request $request)
    {
        return $this->run('Disbursements report', fn (array $f) => $this->reports->disbursements($f), $request, period: true);
    }

    public function branchPerformance(Request $request)
    {
        return $this->run('Branch performance report', fn (array $f) => $this->reports->branchPerformance($f), $request, period: true);
    }

    public function recovery(Request $request)
    {
        return $this->run('Recovery report', fn (array $f) => $this->reports->recovery($f), $request, period: false);
    }

    /**
     * Shared filters: branch_id always; from/to (max one year) for period reports, this month by default.
     */
    protected function run(string $name, callable $report, Request $request, bool $period): JsonResponse
    {
        try {
            $filters = $request->validate([
                'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
                'from' => [$period ? 'nullable' : 'prohibited', 'date_format:Y-m-d'],
                'to' => [$period ? 'nullable' : 'prohibited', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
            ]);

            if ($period) {
                $filters['from'] ??= today()->startOfMonth()->toDateString();
                $filters['to'] ??= today()->toDateString();

                if (now()->parse($filters['from'])->diffInDays(now()->parse($filters['to'])) > 366) {
                    return response()->json(['status' => 'error', 'code' => 'REPORT_PERIOD_TOO_LONG', 'message' => 'A report covers at most one year.'], 422);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => "{$name} generated",
                'data' => $report($filters),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError("Failed to generate {$name}", $th);
        }
    }
}
