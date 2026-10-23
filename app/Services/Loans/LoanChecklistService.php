<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\CollateralStatus;
use App\Models\Collateral;
use App\Models\Loan;
use App\Models\LoanParty;
use App\Services\Documents\DocumentChecklistService;

/**
 * Everything a loan still needs on paper (rule L-3 and section 7.4), in one answer:
 * each person's documents for the role they play, each collateral's proof documents.
 */
class LoanChecklistService
{
    public function __construct(
        protected DocumentChecklistService $documents,
        protected CollateralService $collaterals,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forLoan(Loan $loan): array
    {
        $parties = LoanParty::with('customer')
            ->whereIn('loan_id', $loan->idsWithMemberLoans())
            ->whereNull('released_at')
            ->orderBy('id')
            ->get()
            ->map(function (LoanParty $party) use ($loan) {
                $checklist = $this->documents->forCustomer($party->customer, $party->role, $loan->product);

                return [
                    'party_id' => $party->id,
                    'role' => $party->role->value,
                    'customer' => [
                        'public_id' => $party->customer->public_id,
                        'customer_no' => $party->customer->customer_no,
                        'full_name' => $party->customer->full_name,
                    ],
                ] + $checklist;
            });

        $collaterals = $loan->collaterals()
            ->whereIn('status', [CollateralStatus::Proposed->value, CollateralStatus::Verified->value, CollateralStatus::Perfected->value])
            ->get()
            ->map(function (Collateral $collateral) {
                $missing = $this->collaterals->missingDocuments($collateral);

                return [
                    'public_id' => $collateral->public_id,
                    'type' => $collateral->type,
                    'missing_documents' => $missing,
                    'optional_documents' => $this->collaterals->optionalDocuments($collateral),
                    'complete' => $missing === [],
                ];
            });

        return [
            'complete' => $parties->every(fn ($p) => $p['complete']) && $collaterals->every(fn ($c) => $c['complete']),
            'parties' => $parties->values()->all(),
            'collaterals' => $collaterals->values()->all(),
        ];
    }

}
