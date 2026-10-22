<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\ReviewConfirmRequest;
use App\Http\Requests\Loans\ReviewCorrectionRequest;
use App\Services\Loans\LoanReviewLinkService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;

/**
 * What the customer reaches from the SMS link. No sign-in: the long random token in the link is
 * the key, and it only ever opens that person's review of that loan. Rate limited per link.
 */
class PublicLoanReviewController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        protected LoanReviewLinkService $reviews,
    ) {}

    public function show(string $token): JsonResponse
    {
        return $this->run('Failed to load the review', 'Review data retrieved', fn () => $this->reviews->reviewData($token));
    }

    public function requestOtp(string $token): JsonResponse
    {
        return $this->run('Failed to send the code', 'A code was sent to your registered mobile number', fn () => $this->reviews->requestOtp($token));
    }

    public function confirm(ReviewConfirmRequest $request, string $token): JsonResponse
    {
        return $this->run('Failed to confirm the review', 'Your details were confirmed. Thank you.', fn () => $this->reviews->confirm($token, (string) $request->validated('otp')));
    }

    public function requestCorrection(ReviewCorrectionRequest $request, string $token): JsonResponse
    {
        return $this->run('Failed to submit the correction request', 'Correction request submitted to your loan officer.', fn () => $this->reviews->requestCorrection($token, $request->validated()), 201);
    }

    /**
     * @param  callable(): mixed  $action
     */
    protected function run(string $failure, string $message, callable $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'message' => $message, 'data' => $action()], $status);
        } catch (\Throwable $th) {
            return $this->serverError($failure, $th);
        }
    }
}
