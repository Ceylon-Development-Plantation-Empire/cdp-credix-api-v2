<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\PenaltyWaiverRequest;
use App\Http\Requests\Loans\RestructureRequest;
use App\Http\Requests\Loans\SettleLoanRequest;
use App\Http\Resources\Approvals\ApprovalRequestResource;
use App\Http\Resources\Loans\LoanResource;
use App\Http\Resources\Loans\LoanTransactionResource;
use App\Models\Loan;
use App\Services\Loans\EarlySettlementService;
use App\Services\Loans\PenaltyService;
use App\Services\Loans\RestructureService;
use App\Services\Loans\ScheduleVersionService;
use App\Support\BusinessDate;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * A running loan: statement, early settlement, penalty waiver and restructure requests.
 */
class LoanServicingController extends Controller implements HasMiddleware
{
    use HandlesApiErrors, PaginatesResources;

    public function __construct(
        protected EarlySettlementService $settlements,
        protected PenaltyService $penalties,
        protected RestructureService $restructures,
        protected ScheduleVersionService $versions,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Loan Index', only: ['statement', 'settlementQuote', 'scheduleVersions', 'restructures']),
            new Middleware('permission:Loan Settle', only: ['settle']),
            new Middleware('permission:Penalty Waiver Request', only: ['waivePenalty']),
            new Middleware('permission:Loan Restructure Request', only: ['restructure', 'restructurePreview']),
        ];
    }

    /**
     * Every ledger entry of the loan, oldest first, with where each rupee went.
     */
    public function statement(Request $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $query = $loan->transactions()->with(['payer', 'reversal', 'allocations.installment', 'poster'])->orderBy('value_date')->orderBy('id');

            return response()->json([
                'status' => 'success',
                'message' => 'Loan statement retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), LoanTransactionResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve loan statement', $th);
        }
    }

    public function settlementQuote(string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Settlement quote for today',
                'data' => $this->settlements->quote($loan, BusinessDate::todayImmutable()),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to build settlement quote', $th);
        }
    }

    public function settle(SettleLoanRequest $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $data = $request->validated();
            $transaction = $this->settlements->settle($loan, $data, $data['idempotency_key'], $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Loan settled',
                'data' => [
                    'transaction' => new LoanTransactionResource($transaction->load(['allocations.installment'])),
                    'loan' => new LoanResource($loan->refresh()->load(['customer', 'product', 'branch'])),
                ],
            ], $transaction->wasRecentlyCreated ? 201 : 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to settle loan', $th);
        }
    }

    public function waivePenalty(PenaltyWaiverRequest $request, string $publicId, int $installmentNo)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $approval = $this->penalties->requestWaiver($loan, $installmentNo, $request->validated('amount'), $request->validated('remarks'), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Penalty waiver sent for approval',
                'data' => new ApprovalRequestResource($approval->load(['subject', 'requester'])),
            ], 202);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to request penalty waiver', $th);
        }
    }

    /**
     * The revisions of the repayment schedule: version 1 from disbursement, then one per restructure
     * or renewal, with what each changed and how much was paid on it.
     */
    public function scheduleVersions(string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Schedule versions retrieved successfully',
                'data' => $this->versions->versions($loan),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve schedule versions', $th);
        }
    }

    /**
     * Every restructure request on the loan, newest first: who asked, why, what was proposed and what was decided.
     */
    public function restructures(string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Restructure requests retrieved successfully',
                'data' => $this->versions->restructureRequests($loan)->map(fn ($request) => (new ApprovalRequestResource($request))->resolve() + ['payload' => $request->payload])->values(),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve restructure requests', $th);
        }
    }

    /**
     * What a restructure would do, without sending it for approval: the new schedule and instalment.
     */
    public function restructurePreview(RestructureRequest $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Restructure preview calculated',
                'data' => $this->restructures->preview($loan, $request->validated()),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to preview the restructure', $th);
        }
    }

    public function restructure(RestructureRequest $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $approval = $this->restructures->request($loan, $request->validated(), $request->validated('remarks'), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Restructure sent for approval',
                'data' => new ApprovalRequestResource($approval->load(['subject', 'requester'])),
            ], 202);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to request restructure', $th);
        }
    }
}
