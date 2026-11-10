<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\RenewalQuoteRequest;
use App\Http\Requests\Loans\RenewGoldLoanRequest;
use App\Http\Resources\Loans\LoanTransactionResource;
use App\Models\Loan;
use App\Services\Loans\GoldRenewalService;
use App\Traits\HandlesApiErrors;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Gold loan renewal at the counter: a quote, then the renewal with the quoted amount.
 */
class GoldLoanRenewalController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected GoldRenewalService $renewals,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Loan Index', only: ['quote']),
            new Middleware('permission:Gold Loan Renew', only: ['renew']),
        ];
    }

    public function quote(RenewalQuoteRequest $request, string $publicId)
    {
        try {
            $data = $request->validated();
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Renewal quote generated',
                'data' => $this->renewals->quote($loan, isset($data['tenure']) ? (int) $data['tenure'] : null),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to generate renewal quote', $th);
        }
    }

    public function renew(RenewGoldLoanRequest $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $data = $request->validated();
            $transaction = $this->renewals->renew($loan, $data, $data['idempotency_key'], $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Gold loan renewed',
                'data' => [
                    'transaction' => new LoanTransactionResource($transaction->load(['loan', 'allocations.installment', 'poster'])),
                    'renewal_count' => $transaction->loan->renewal_count,
                    'maturity_date' => $transaction->loan->maturity_date?->toDateString(),
                ],
            ], $transaction->wasRecentlyCreated ? 201 : 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to renew gold loan', $th);
        }
    }
}
