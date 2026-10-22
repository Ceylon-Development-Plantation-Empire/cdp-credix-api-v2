<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Loan;
use App\Services\Loans\InvestmentCollateralService;
use App\Support\Mask;
use App\Traits\ActivityLogTrait;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * The security step of the loan wizard, for the parts that ask CDP Core.
 *
 * investment-lookup shows one policy (NIC + policy number) before anything is saved. It reads CDP
 * Core and stores nothing; the policy is added with POST /loans/{id}/collaterals/cdp-investment,
 * which checks every rule again against Core.
 */
class LoanSecurityController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, HandlesApiErrors;

    public function __construct(
        protected InvestmentCollateralService $investments,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Loan Create|Loan Update|Loan Collateral Manage|CDP Customer Verification', only: ['investmentLookup']),
        ];
    }

    public function investmentLookup(Request $request)
    {
        try {
            $data = $request->validate([
                'nic' => ['required', 'string', 'max:20'],
                'policy_number' => ['required', 'string', 'max:100'],
                'customer_public_ids' => ['nullable', 'array'],
                'customer_public_ids.*' => ['string', 'ulid', 'distinct'],
                'loan_public_id' => ['nullable', 'string', 'ulid'],
                'product_slug' => ['nullable', 'string', 'max:80'],
            ]);

            $borrowers = empty($data['customer_public_ids']) ? [] : Customer::whereIn('public_id', $data['customer_public_ids'])->get();
            $loanId = ! empty($data['loan_public_id']) ? Loan::where('public_id', $data['loan_public_id'])->value('id') : null;

            $result = $this->investments->lookup(
                $data['nic'],
                $data['policy_number'],
                $borrowers,
                $loanId,
                $this->investments->maxLoanPercentFor($data['product_slug'] ?? null),
            );

            $this->logActivity('INDEX', 'LoanSecurity', 'CDP investment looked up for a loan security', [
                'policy_number' => $result['investment']['policy_number'],
                'nic' => Mask::idNumber(trim($data['nic'])),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Investment found',
                'data' => $result,
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to look up the investment', $th);
        }
    }
}
