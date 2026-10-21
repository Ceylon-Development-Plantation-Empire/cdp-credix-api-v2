<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\GlAccount;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Accounting\GlReportService;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Trial balance, account statement, reconciliation to the loans, and closing the books.
 */
class GlReportController extends Controller implements HasMiddleware
{
    use HandlesApiErrors, PaginatesResources;

    public function __construct(
        protected GlReportService $reports,
        protected GeneralLedgerService $gl,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:GL Report View', except: ['closePeriod']),
            new Middleware('permission:GL Period Close', only: ['closePeriod']),
        ];
    }

    public function trialBalance(Request $request)
    {
        try {
            $filters = $request->validate([
                'as_of' => ['nullable', 'date_format:Y-m-d'],
                'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
                'include_zero' => ['sometimes', 'boolean'],
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Trial balance generated',
                'data' => $this->reports->trialBalance(
                    CarbonImmutable::parse($filters['as_of'] ?? today()->toDateString()),
                    $filters['branch_id'] ?? null,
                    $request->boolean('include_zero'),
                ),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to generate trial balance', $th);
        }
    }

    public function statement(Request $request, int $id)
    {
        try {
            $account = GlAccount::find($id);

            if (! $account) {
                return $this->notFound('GL account not found');
            }

            $filters = $request->validate([
                'from' => ['nullable', 'date_format:Y-m-d'],
                'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
                'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            ]);

            $from = CarbonImmutable::parse($filters['from'] ?? today()->startOfMonth()->toDateString());
            $to = CarbonImmutable::parse($filters['to'] ?? today()->toDateString());

            if ($from->greaterThan($to) || $from->diffInDays($to) > 366) {
                return response()->json(['status' => 'error', 'code' => 'REPORT_PERIOD_TOO_LONG', 'message' => 'A statement covers one period of at most one year.'], 422);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Account statement generated',
                'data' => $this->reports->statement($account, $from, $to, $filters['branch_id'] ?? null, $this->perPage($request)),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to generate account statement', $th);
        }
    }

    public function reconciliation(Request $request)
    {
        try {
            $filters = $request->validate(['branch_id' => ['nullable', 'integer', 'exists:branches,id']]);

            return response()->json([
                'status' => 'success',
                'message' => 'Reconciliation generated',
                'data' => $this->reports->reconciliation($filters['branch_id'] ?? null),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to generate reconciliation', $th);
        }
    }

    public function period()
    {
        try {
            return response()->json([
                'status' => 'success',
                'message' => 'Accounting period retrieved',
                'data' => ['closed_through' => $this->gl->closedThrough()?->toDateString()],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve accounting period', $th);
        }
    }

    public function closePeriod(Request $request)
    {
        try {
            $data = $request->validate(['through' => ['required', 'date_format:Y-m-d']]);
            $through = $this->gl->closePeriod(CarbonImmutable::parse($data['through']), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => "Books closed through {$through->toDateString()}",
                'data' => ['closed_through' => $through->toDateString()],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to close the period', $th);
        }
    }
}
