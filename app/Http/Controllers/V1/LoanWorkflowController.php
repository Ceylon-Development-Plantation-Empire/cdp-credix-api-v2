<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\ApproveLoanRequest;
use App\Http\Requests\Loans\LoanRemarksRequest;
use App\Http\Requests\Loans\OptionalRemarksRequest;
use App\Http\Resources\Loans\LoanResource;
use App\Models\Loan;
use App\Services\Loans\LoanApplicationService;
use App\Services\Loans\LoanReviewService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * One explicit endpoint per workflow step (no generic "update status"). Who may do a step is a
 * permission; who may NOT (maker-checker) is decided by the services.
 */
class LoanWorkflowController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected LoanApplicationService $applications,
        protected LoanReviewService $review,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Loan Submit', only: ['submit']),
            new Middleware('permission:Loan Review', only: ['startReview', 'returnForCorrection']),
            new Middleware('permission:Loan Verify', only: ['verify']),
            new Middleware('permission:Loan Approve', only: ['approve']),
            new Middleware('permission:Loan Reject', only: ['reject']),
            new Middleware('permission:Loan Cancel', only: ['cancel']),
        ];
    }

    public function submit(Request $request, string $publicId)
    {
        return $this->step($publicId, 'Application submitted', 'Failed to submit application',
            fn (Loan $loan) => $this->applications->submit($loan, $request->user()));
    }

    public function startReview(Request $request, string $publicId)
    {
        return $this->step($publicId, 'Review started', 'Failed to start review',
            fn (Loan $loan) => $this->review->startReview($loan, $request->user()));
    }

    public function returnForCorrection(LoanRemarksRequest $request, string $publicId)
    {
        return $this->step($publicId, 'Application returned for correction', 'Failed to return application',
            fn (Loan $loan) => $this->review->returnForCorrection($loan, $request->validated('remarks'), $request->user()));
    }

    public function verify(OptionalRemarksRequest $request, string $publicId)
    {
        $remarks = $request->validated()['remarks'] ?? null;

        return $this->step($publicId, 'Application verified', 'Failed to verify application',
            fn (Loan $loan) => $this->review->verify($loan, $remarks, $request->user()));
    }

    public function approve(ApproveLoanRequest $request, string $publicId)
    {
        return $this->step($publicId, 'Loan approved', 'Failed to approve loan',
            fn (Loan $loan) => $this->review->approve($loan, $request->validated(), $request->user()));
    }

    public function reject(LoanRemarksRequest $request, string $publicId)
    {
        return $this->step($publicId, 'Application rejected', 'Failed to reject application',
            fn (Loan $loan) => $this->review->reject($loan, $request->validated('remarks'), $request->user()));
    }

    public function cancel(LoanRemarksRequest $request, string $publicId)
    {
        return $this->step($publicId, 'Loan cancelled', 'Failed to cancel loan',
            fn (Loan $loan) => $this->review->cancel($loan, $request->validated('remarks'), $request->user()));
    }

    /**
     * @param  callable(Loan): Loan  $action
     */
    protected function step(string $publicId, string $success, string $failure, callable $action): JsonResponse
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $loan = $action($loan);

            return response()->json([
                'status' => 'success',
                'message' => $success,
                'data' => new LoanResource($loan->load(['customer', 'borrowerGroup', 'product', 'branch', 'parties.customer', 'memberLoans.parties.customer'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError($failure, $th);
        }
    }
}
