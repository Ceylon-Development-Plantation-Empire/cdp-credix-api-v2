<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1\External;

use App\Enums\IdType;
use App\Http\Controllers\Controller;
use App\Models\Collateral;
use App\Models\Customer;
use App\Services\Loans\InvestmentCollateralService;
use App\Support\Mask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What CDP Core asks CrediX before it pays an investment policy out.
 *
 *   GET /api/v1/external/core/policy-hold?policy_number=CDP-JFNM-00000138
 *   GET /api/v1/external/core/policy-hold?id_number=199231303062
 *   Header: X-Core-Key
 *
 * `held` is true when an open CrediX loan (anything but settled, written off, cancelled or
 * rejected) is secured by the policy. Core should block a withdrawal, maturity payout, surrender
 * or transfer while `held` is true. The answer is always derived from the collateral and the
 * loan's current status.
 */
class CorePolicyHoldController extends Controller
{
    public function __construct(
        protected InvestmentCollateralService $investments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'policy_number' => ['nullable', 'string', 'max:100', 'required_without:id_number'],
            'id_number' => ['nullable', 'string', 'max:50', 'required_without:policy_number'],
        ]);

        $query = $this->investments->holding(! empty($data['policy_number']) ? $data['policy_number'] : null)
            ->whereNotNull('details->policy_number')
            ->with(['loan' => fn ($q) => $q->withoutGlobalScopes()->with('customer:id,full_name,id_number,id_type')]);

        if (! empty($data['id_number'])) {
            // The same person under the old (V/X) or the new (12-digit) NIC: the customer is found by its blind index.
            $normalized = IdType::Nic->normalize($data['id_number']) ?? strtoupper(preg_replace('/[\s-]+/', '', $data['id_number']));
            $ownerIds = Customer::withoutGlobalScope('branch')->where('id_number_hash', Customer::idNumberHash($normalized))->pluck('id');

            $query->where(fn ($q) => $q->whereIn('owner_customer_id', $ownerIds)
                ->orWhereHas('loan', fn ($l) => $l->withoutGlobalScopes()->whereIn('customer_id', $ownerIds)));
        }

        $collaterals = $query->orderByDesc('id')->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'held' => $collaterals->isNotEmpty(),
                'loans' => $collaterals->map(function (Collateral $collateral) {
                    $loan = $collateral->loan;
                    $policy = InvestmentCollateralService::normalisePolicy($collateral->details['policy_number'] ?? '');

                    return [
                        'policy_numbers' => [$policy],
                        'policy_number' => $policy,
                        'reference' => $loan->loan_no ?? $loan->application_no,
                        'status' => $loan->status->value,
                        'amount' => (string) ($loan->approved_amount ?? $loan->requested_amount),
                        'customer' => [
                            'name' => $loan->customer?->full_name,
                            'id_number' => Mask::idNumber($loan->customer?->normalizedIdNumber()),
                        ],
                        'applied_at' => $loan->created_at?->toDateString(),
                    ];
                })->values(),
            ],
        ]);
    }
}
