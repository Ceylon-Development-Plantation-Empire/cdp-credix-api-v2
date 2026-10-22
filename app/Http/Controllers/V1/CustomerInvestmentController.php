<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Loans\InvestmentCollateralService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * The loan wizard's investment picker.
 *
 *   GET /customers/{publicId}/investments?product_slug=gold-pawning
 *
 * The customer's approved CDP Core policies, live from Core, and whether another open loan already
 * holds each. With a product, the lending % that product has for CDP investments is shown for
 * information. Nothing is stored; the officer picks one and adds it as collateral.
 */
class CustomerInvestmentController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected InvestmentCollateralService $investments,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Loan Create|Loan Collateral Manage|CDP Customer Verification', only: ['index']),
        ];
    }

    public function index(Request $request, string $publicId)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            $percent = $this->investments->maxLoanPercentFor($request->query('product_slug'));

            return response()->json([
                'status' => 'success',
                'message' => 'Investments retrieved successfully',
                'data' => $this->investments->investmentsFor($customer, $percent) + ['max_loan_percentage' => $percent],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve the customer investments', $th);
        }
    }
}
