<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\LoanRemarksRequest;
use App\Http\Requests\Loans\PostPaymentRequest;
use App\Http\Resources\Approvals\ApprovalRequestResource;
use App\Http\Resources\Loans\LoanTransactionResource;
use App\Models\ApprovalRequest;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Services\Loans\RepaymentService;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Repayments. A transaction has no branch of its own; it is always found through its loan, so the
 * loan's branch scope protects it (whereHas applies the loan's global scope).
 */
class PaymentController extends Controller implements HasMiddleware
{
    use HandlesApiErrors, PaginatesResources;

    public function __construct(
        protected RepaymentService $repayments,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Payment Index', only: ['index', 'show']),
            new Middleware('permission:Payment Create', only: ['store']),
            new Middleware('permission:Payment Reversal Request', only: ['reverse']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $query = LoanTransaction::with(['loan.customer', 'payer', 'reversal', 'allocations.installment'])
                ->whereHas('loan')
                ->whereIn('type', [TransactionType::Repayment->value, TransactionType::EarlySettlement->value, TransactionType::Reversal->value])
                ->latest('id');

            if ($request->filled('loan_public_id')) {
                $query->whereHas('loan', fn ($q) => $q->where('public_id', $request->loan_public_id));
            }

            if ($request->filled('from')) {
                $query->whereDate('value_date', '>=', $request->date('from'));
            }

            if ($request->filled('to')) {
                $query->whereDate('value_date', '<=', $request->date('to'));
            }

            if ($request->filled('channel')) {
                $query->where('channel', $request->channel);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payments retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), LoanTransactionResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve payments', $th);
        }
    }

    /**
     * 201 with the transaction when posted; 202 with the approval request when the payment is
     * back-dated beyond the limit and waits for a second person.
     */
    public function store(PostPaymentRequest $request)
    {
        try {
            $data = $request->validated();
            $loan = Loan::where('public_id', $data['loan_public_id'])->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $result = $this->repayments->receive($loan, $data, $data['idempotency_key'], $request->user());

            if ($result instanceof ApprovalRequest) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'The payment is back-dated beyond the allowed days and was sent for approval',
                    'data' => new ApprovalRequestResource($result->load(['subject', 'requester'])),
                ], 202);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payment posted successfully',
                'data' => new LoanTransactionResource($result->load(['loan', 'payer', 'allocations.installment', 'poster'])),
            ], $result->wasRecentlyCreated ? 201 : 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to post payment', $th);
        }
    }

    public function show(string $publicId)
    {
        try {
            $transaction = $this->find($publicId);

            if (! $transaction) {
                return $this->notFound('Payment not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payment retrieved successfully',
                'data' => new LoanTransactionResource($transaction->load(['loan', 'payer', 'reversal', 'allocations.installment', 'poster'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve payment', $th);
        }
    }

    /**
     * Ask for a reversal; it is posted when another person with Payment Reversal Approve approves it.
     */
    public function reverse(LoanRemarksRequest $request, string $publicId)
    {
        try {
            $transaction = $this->find($publicId);

            if (! $transaction) {
                return $this->notFound('Payment not found');
            }

            $approval = $this->repayments->requestReversal($transaction, $request->validated('remarks'), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Reversal sent for approval',
                'data' => new ApprovalRequestResource($approval->load(['subject', 'requester'])),
            ], 202);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to request reversal', $th);
        }
    }

    protected function find(string $publicId): ?LoanTransaction
    {
        return LoanTransaction::where('public_id', $publicId)->whereHas('loan')->first();
    }
}
