<?php

declare(strict_types=1);

namespace App\Http\Resources\Loans;

use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A loan with its terms, assessment, people and collateral. The cost of finance is one column
 * (profit_amount); cost_label tells the screen to call it "Interest" or "Profit".
 *
 * Internal ids, the terms snapshot and hashes are never returned.
 *
 * @mixin Loan
 */
class LoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return (new LoanListResource($this->resource))->toArray($request) + [
            'purpose' => $this->purpose,
            // Additive (guide v2.1): custom fields defined by the business.
            'meta' => $this->meta ?? (object) [],
            'is_member_loan' => $this->isMemberLoan(),
            'terms' => [
                'finance_basis' => $this->finance_basis->value,
                'cost_label' => $this->finance_basis->costLabel(),
                'pricing_method' => $this->pricing_method->value,
                'repayment_frequency' => $this->repayment_frequency->value,
                'rate' => (string) $this->rate,
                'tenure' => $this->tenure,
                'requested_amount' => (string) $this->requested_amount,
                'approved_amount' => $this->approved_amount !== null ? (string) $this->approved_amount : null,
                'financed_amount' => $this->financed_amount !== null ? (string) $this->financed_amount : null,
                'profit_amount' => $this->profit_amount !== null ? (string) $this->profit_amount : null,
                'installment_amount' => $this->installment_amount !== null ? (string) $this->installment_amount : null,
                'upfront_charges' => (string) $this->upfront_charges,
                'net_disbursement_amount' => $this->net_disbursement_amount !== null ? (string) $this->net_disbursement_amount : null,
                'residual_value' => (string) $this->residual_value,
            ],
            'assessment' => [
                'monthly_income' => $this->assessed_monthly_income,
                'monthly_expenses' => $this->assessed_monthly_expenses,
                'existing_obligations' => $this->existing_obligations,
                'dsr_percent' => $this->dsr_percent,
            ],
            // What the customer answered after approval: on hold (with the officer's note of why) or not.
            'offer' => [
                'on_hold' => $this->offer_on_hold_at !== null,
                'on_hold_at' => $this->offer_on_hold_at?->toApiString(),
                'remarks' => $this->offer_remarks,
            ],
            'dates' => [
                'submitted_at' => $this->submitted_at?->toApiString(),
                'approved_at' => $this->approved_at?->toApiString(),
                'accepted_at' => $this->accepted_at?->toApiString(),
                'disbursed_at' => $this->disbursed_at?->toApiString(),
                'first_due_date' => $this->first_due_date?->toDateString(),
                'maturity_date' => $this->maturity_date?->toDateString(),
                'closed_at' => $this->closed_at?->toApiString(),
            ],
            'closure_reason' => $this->closure_reason?->value,
            'balances' => [
                'outstanding_principal' => (string) $this->outstanding_principal,
                'outstanding_profit' => (string) $this->outstanding_profit,
                'outstanding_charges' => (string) $this->outstanding_charges,
                'outstanding_penalty' => (string) $this->outstanding_penalty,
                'arrears_amount' => (string) $this->arrears_amount,
                'risk_stage' => $this->risk_stage,
            ],
            'officer' => $this->whenLoaded('officer', fn () => $this->officer ? ['id' => $this->officer->id, 'name' => $this->officer->name] : null),
            'parties' => LoanPartyResource::collection($this->whenLoaded('parties')),
            'member_loans' => LoanMemberResource::collection($this->whenLoaded('memberLoans')),
            'collaterals' => CollateralResource::collection($this->whenLoaded('collaterals')),
        ];
    }
}
