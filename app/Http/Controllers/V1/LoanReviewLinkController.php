<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\ResolveCorrectionRequest;
use App\Http\Requests\Loans\SendReviewLinkRequest;
use App\Http\Requests\Loans\StaffReviewOtpRequest;
use App\Http\Requests\Loans\StaffReviewVerifyRequest;
use App\Models\Loan;
use App\Services\Loans\LoanReviewLinkService;
use App\Support\PhoneNormalizer;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * The customer review link, seen from the officer's side: who has confirmed, send the link,
 * help a customer confirm at the branch, and answer the corrections customers ask for.
 */
class LoanReviewLinkController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected LoanReviewLinkService $reviews,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Loan Index', only: ['status', 'preview', 'customerDetails', 'corrections']),
            new Middleware('permission:Loan Offer Manage', only: ['sendLink', 'sendOtp', 'verifyOtp', 'resolveCorrection']),
        ];
    }

    public function status(string $publicId): JsonResponse
    {
        return $this->run($publicId, 'Failed to load the review status', fn (Loan $loan) => $this->ok('Review status loaded', $this->reviews->status($loan)));
    }

    public function preview(string $publicId): JsonResponse
    {
        return $this->run($publicId, 'Failed to load the review preview', fn (Loan $loan) => $this->ok('Preview loaded', $this->reviews->preview($loan)));
    }

    /**
     * What this customer sees on their link, for an officer who shows it to a customer without a smartphone.
     */
    public function customerDetails(string $publicId, string $customerPublicId): JsonResponse
    {
        return $this->run($publicId, 'Failed to load the customer review', fn (Loan $loan) => $this->ok('Review loaded', $this->reviews->customerDetails($loan, $customerPublicId)));
    }

    public function sendLink(SendReviewLinkRequest $request, string $publicId): JsonResponse
    {
        return $this->run($publicId, 'Failed to send the review link', function (Loan $loan) use ($request) {
            $customers = $this->reviews->sendLinks($loan, $request->validated('customer_public_ids'), $request->user());
            $sent = collect($customers)->where('link_sent', true);

            $message = match (true) {
                $sent->isEmpty() => 'Nothing to send: everyone has already confirmed.',
                $sent->every(fn ($c) => $c['sms_sent']) => 'Review link sent to the customer by SMS.',
                default => 'The link was created but the SMS could not be sent. Try again.',
            };

            return $this->ok($message, ['loan_public_id' => $loan->public_id, 'customers' => $customers]);
        });
    }

    public function sendOtp(StaffReviewOtpRequest $request, string $publicId, string $customerPublicId): JsonResponse
    {
        return $this->run($publicId, 'Failed to send the confirmation code', function (Loan $loan) use ($request, $customerPublicId) {
            $challenge = $this->reviews->staffSendOtp($loan, $customerPublicId, (string) $request->validated('reason'), $request->user());

            return $this->ok('Verification code sent', [
                'customer_id' => $customerPublicId,
                'status' => 'pending',
                'staff_assist_reason' => $request->validated('reason'),
                'sent_to' => PhoneNormalizer::mask($challenge->destination),
                'otp_expires_at' => $challenge->expires_at->toApiString(),
            ]);
        });
    }

    public function verifyOtp(StaffReviewVerifyRequest $request, string $publicId, string $customerPublicId): JsonResponse
    {
        return $this->run($publicId, 'Failed to verify the confirmation code', function (Loan $loan) use ($request, $customerPublicId) {
            $party = $this->reviews->staffVerifyOtp($loan, $customerPublicId, (string) $request->validated('otp'), $request->user());

            return $this->ok('Customer confirmed', [
                'customer_id' => $party->customer->public_id,
                'full_name' => $party->customer->full_name,
                'status' => 'confirmed',
                'confirmed_at' => $party->review_confirmed_at->toApiString(),
            ]);
        });
    }

    public function corrections(string $publicId): JsonResponse
    {
        return $this->run($publicId, 'Failed to load correction requests', fn (Loan $loan) => $this->ok('Correction requests loaded', $this->reviews->corrections($loan)->all()));
    }

    public function resolveCorrection(ResolveCorrectionRequest $request, string $publicId, int $requestId): JsonResponse
    {
        return $this->run($publicId, 'Failed to answer the correction request', fn (Loan $loan) => $this->ok(
            'Correction request answered',
            $this->reviews->resolveCorrection($loan, $requestId, (string) $request->validated('outcome'), (string) $request->validated('remarks'), $request->user()),
        ));
    }

    /**
     * @param  callable(Loan): JsonResponse  $action
     */
    protected function run(string $publicId, string $failure, callable $action): JsonResponse
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            return $loan ? $action($loan) : $this->notFound('Loan not found');
        } catch (\Throwable $th) {
            return $this->serverError($failure, $th);
        }
    }

    protected function ok(string $message, mixed $data): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], 200);
    }
}
