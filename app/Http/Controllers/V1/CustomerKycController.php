<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\VerifyOtpRequest;
use App\Http\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Services\Customers\KycService;
use App\Services\Security\OtpService;
use App\Support\PhoneNormalizer;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CustomerKycController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected KycService $kyc,
        protected OtpService $otp,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Customer KYC Verify'),
        ];
    }

    /**
     * Sends the code to the customer's registered mobile. The response never contains the code.
     */
    public function sendOtp(Request $request, string $publicId)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            $challenge = $this->kyc->sendOtp($customer, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Verification code sent to the customer',
                'data' => [
                    'sent_to' => PhoneNormalizer::mask($challenge->destination),
                    'expires_at' => $challenge->expires_at->toIso8601String(),
                ],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to send verification code', $th);
        }
    }

    public function resendOtp(string $publicId)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            $challenge = $this->kyc->resendOtp($customer);

            return response()->json([
                'status' => 'success',
                'message' => 'Verification code sent again',
                'data' => [
                    'sent_to' => PhoneNormalizer::mask($challenge->destination),
                    'expires_at' => $challenge->expires_at->toIso8601String(),
                ],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to resend verification code', $th);
        }
    }

    public function verify(VerifyOtpRequest $request, string $publicId)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            $customer = $this->kyc->verify($customer, $request->validated('code'), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Customer details confirmed (KYC verified)',
                'data' => new CustomerResource($customer->load('branch')),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to verify code', $th);
        }
    }
}
