<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CdpVerifyCustomerRequest;
use App\Services\Customers\CdpConnectService;
use App\Support\Mask;
use App\Traits\ActivityLogTrait;
use App\Traits\HandlesApiErrors;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * The CDP investment check for Customer 360 and customer search. Read-only, from CDP Connect.
 */
class CdpCustomerVerificationController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, HandlesApiErrors;

    public function __construct(
        protected CdpConnectService $cdp,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:CDP Customer Verification', only: ['checkCustomer']),
        ];
    }

    public function checkCustomer(CdpVerifyCustomerRequest $request)
    {
        try {
            $data = $request->validated();

            // The ID number is personal data: the activity log keeps it masked.
            $this->logActivity('INDEX', 'CdpCustomerVerification', 'CDP investment check', [
                'id_number' => Mask::idNumber($data['id_number']),
                'id_type' => $data['id_type'] ?? null,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'CDP customer verified successfully',
                'data' => $this->cdp->customerInvestments($data['id_number'], $data['id_type'] ?? null, $data['status'] ?? null),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to verify the customer with CDP Connect', $th);
        }
    }
}
