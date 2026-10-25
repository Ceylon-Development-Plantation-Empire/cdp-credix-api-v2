<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\AddGuarantorRequest;
use App\Http\Requests\Loans\LoanRemarksRequest;
use App\Http\Requests\Loans\OptionalRemarksRequest;
use App\Http\Requests\Loans\UpdateGuarantorRequest;
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
 * Guarantors, and the officer recording that a borrower accepted the offer (no code).
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
            new Middleware('permission:Loan Guarantor Index', only: ['guarantors']),
            new Middleware('permission:Loan Guarantor Create', only: ['addGuarantor']),
            new Middleware('permission:Loan Guarantor Update', only: ['updateGuarantor']),
            new Middleware('permission:Loan Guarantor Delete', only: ['removeGuarantor']),
            new Middleware('permission:Loan Offer Manage', only: ['acceptOffer', 'holdOffer', 'declineOffer']),
        ];
    }

    public function guarantors(string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Guarantors retrieved successfully',
                'data' => LoanPartyResource::collection($loan->guarantors()->with('customer')->get()),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve guarantors', $th);
        }
    }

    public function updateGuarantor(UpdateGuarantorRequest $request, string $publicId, int $partyId)
    {
        try {
            [$loan, $party] = $this->find($publicId, $partyId);

            if (! $party) {
                return $this->notFound('Guarantor not found');
            }

            $party = $this->parties->updateGuarantor($loan, $party, $request->validated('relationship'), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Guarantor updated successfully',
                'data' => new LoanPartyResource($party->load('customer')),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update guarantor', $th);
        }
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

    public function acceptOffer(OptionalRemarksRequest $request, string $publicId, int $partyId)
    {
        try {
            [$loan, $party] = $this->find($publicId, $partyId);

            if (! $party) {
                return $this->notFound('Party not found');
            }

            $loan = $this->parties->acceptOffer($loan, $party, $request->validated()['remarks'] ?? null, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Offer accepted',
                'data' => new LoanResource($loan->load(['customer', 'borrowerGroup', 'product', 'branch', 'parties.customer', 'memberLoans.parties.customer'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to accept offer', $th);
        }
    }

    public function holdOffer(LoanRemarksRequest $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $loan = $this->parties->holdOffer($loan, $request->validated('remarks'), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Offer put on hold',
                'data' => new LoanResource($loan->load(['customer', 'borrowerGroup', 'product', 'branch'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to put the offer on hold', $th);
        }
    }

    public function declineOffer(LoanRemarksRequest $request, string $publicId)
    {
        try {
            $remarks = $request->validated('remarks');
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
