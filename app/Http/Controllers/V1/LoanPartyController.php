<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\VerifyOtpRequest;
use App\Http\Requests\Loans\AddGuarantorRequest;
use App\Http\Resources\Loans\LoanPartyResource;
use App\Http\Resources\Loans\LoanResource;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanParty;
use App\Services\Loans\LoanPartyService;
use App\Support\PhoneNormalizer;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Guarantors and the OTP confirmations of parties: guarantor consent and offer acceptance.
 * Codes are sent to the party's registered mobile and never appear in any response.
 */
class LoanPartyController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected LoanPartyService $parties,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Loan Guarantor Manage', only: ['addGuarantor', 'removeGuarantor', 'sendConsent', 'verifyConsent']),
            new Middleware('permission:Loan Offer Manage', only: ['sendOffer', 'acceptOffer', 'declineOffer']),
        ];
    }

    public function addGuarantor(AddGuarantorRequest $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();
            $customer = Customer::where('public_id', $request->validated('customer_public_id'))->first();

            if (! $loan || ! $customer) {
                return $this->notFound($loan ? 'Customer not found' : 'Loan not found');
            }

            $party = $this->parties->addGuarantor($loan, $customer, $request->validated('relationship'), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Guarantor added successfully',
                'data' => new LoanPartyResource($party->load('customer')),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to add guarantor', $th);
        }
    }

    public function removeGuarantor(Request $request, string $publicId, int $partyId)
    {
        try {
            [$loan, $party] = $this->find($publicId, $partyId);

            if (! $party) {
                return $this->notFound('Guarantor not found');
            }

            $this->parties->removeGuarantor($loan, $party, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Guarantor removed successfully',
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to remove guarantor', $th);
        }
    }

    public function sendConsent(Request $request, string $publicId, int $partyId)
    {
        try {
            [$loan, $party] = $this->find($publicId, $partyId);

            if (! $party) {
                return $this->notFound('Party not found');
            }

            $challenge = $this->parties->sendGuarantorConsent($loan, $party, $request->user());

            return $this->codeSent($challenge->destination, $challenge->expires_at);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to send consent code', $th);
        }
    }

    public function verifyConsent(VerifyOtpRequest $request, string $publicId, int $partyId)
    {
        try {
            [$loan, $party] = $this->find($publicId, $partyId);

            if (! $party) {
                return $this->notFound('Party not found');
            }

            $party = $this->parties->verifyGuarantorConsent($loan, $party, $request->validated('code'), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Guarantor consent recorded',
                'data' => new LoanPartyResource($party->load('customer')),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to verify consent code', $th);
        }
    }

    public function sendOffer(Request $request, string $publicId, int $partyId)
    {
        try {
            [$loan, $party] = $this->find($publicId, $partyId);

            if (! $party) {
                return $this->notFound('Party not found');
            }

            $challenge = $this->parties->sendOffer($loan, $party, $request->user());

            return $this->codeSent($challenge->destination, $challenge->expires_at);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to send offer code', $th);
        }
    }

    public function acceptOffer(VerifyOtpRequest $request, string $publicId, int $partyId)
    {
        try {
            [$loan, $party] = $this->find($publicId, $partyId);

            if (! $party) {
                return $this->notFound('Party not found');
            }

            $loan = $this->parties->acceptOffer($loan, $party, $request->validated('code'), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Offer accepted',
                'data' => new LoanResource($loan->load(['customer', 'borrowerGroup', 'product', 'branch', 'parties.customer', 'memberLoans.parties.customer'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to accept offer', $th);
        }
    }

    public function declineOffer(Request $request, string $publicId)
    {
        try {
            $remarks = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']])['remarks'] ?? null;
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $loan = $this->parties->declineOffer($loan, $remarks, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Offer declined; the loan is cancelled',
                'data' => new LoanResource($loan->load(['customer', 'borrowerGroup', 'product', 'branch'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to decline offer', $th);
        }
    }

    /**
     * @return array{0: Loan|null, 1: LoanParty|null}
     */
    protected function find(string $publicId, int $partyId): array
    {
        $loan = Loan::where('public_id', $publicId)->first();

        return [$loan, $loan ? $this->parties->findParty($loan, $partyId) : null];
    }

    protected function codeSent(string $destination, \DateTimeInterface $expiresAt)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Verification code sent',
            'data' => [
                'sent_to' => PhoneNormalizer::mask($destination),
                'expires_at' => $expiresAt->format(DATE_ATOM),
            ],
        ], 200);
    }
}
