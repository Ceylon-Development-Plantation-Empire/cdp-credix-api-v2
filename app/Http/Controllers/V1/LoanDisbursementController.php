<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\DisburseLoanRequest;
use App\Http\Resources\Loans\LoanResource;
use App\Models\Loan;
use App\Services\Loans\DisbursementService;
use App\Traits\HandlesApiErrors;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class LoanDisbursementController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected DisbursementService $disbursements,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Loan Disburse'),
        ];
    }

    public function disburse(DisburseLoanRequest $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $data = $request->validated();
            $loan = $this->disbursements->disburse($loan, $data, $data['idempotency_key'], $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Loan disbursed successfully',
                'data' => new LoanResource($loan->load(['customer', 'borrowerGroup', 'product', 'branch', 'parties.customer', 'memberLoans.parties.customer'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to disburse loan', $th);
        }
    }
}
