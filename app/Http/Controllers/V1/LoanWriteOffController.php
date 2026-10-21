<?php

namespace App\Http\Controllers\V1;

use App\Enums\TransactionType;
use App\Finance\Support\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\LoanRemarksRequest;
use App\Http\Requests\Loans\RecoveryPaymentRequest;
use App\Http\Resources\Approvals\ApprovalRequestResource;
use App\Http\Resources\Loans\LoanTransactionResource;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Services\Loans\WriteOffService;
use App\Traits\HandlesApiErrors;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Write-off requests (decided in the approval inbox) and recoveries on written-off loans.
 */
class LoanWriteOffController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected WriteOffService $writeOffs,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Loan Write Off Request', only: ['request']),
            new Middleware('permission:Loan Index', only: ['summary']),
            new Middleware('permission:Write Off Recovery Create', only: ['recover']),
        ];
    }

    public function request(LoanRemarksRequest $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $approval = $this->writeOffs->request($loan, $request->validated('remarks'), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Write-off sent for approval',
                'data' => new ApprovalRequestResource($approval->load(['subject', 'requester'])),
            ], 202);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to request write-off', $th);
        }
    }

    /**
     * How much was written off and how much of it has come back.
     */
    public function summary(string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $writtenOff = Money::of((string) (LoanTransaction::where('loan_id', $loan->id)->where('type', TransactionType::WriteOff->value)->sum('amount') ?? 0));
            $unrecovered = $this->writeOffs->unrecovered($loan);

            return response()->json([
                'status' => 'success',
                'message' => 'Write-off summary retrieved',
                'data' => [
                    'status' => $loan->status->value,
                    'written_off' => (string) $writtenOff,
                    'recovered' => (string) $writtenOff->minus($unrecovered),
                    'unrecovered' => (string) $unrecovered,
                ],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve write-off summary', $th);
        }
    }

    public function recover(RecoveryPaymentRequest $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $data = $request->validated();
            $transaction = $this->writeOffs->recover($loan, $data, $data['idempotency_key'], $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Recovery posted successfully',
                'data' => new LoanTransactionResource($transaction->load(['loan', 'poster'])),
            ], $transaction->wasRecentlyCreated ? 201 : 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to post recovery', $th);
        }
    }
}
