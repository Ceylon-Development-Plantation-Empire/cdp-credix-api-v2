<?php

declare(strict_types=1);

namespace App\Http\Resources\Loans;

use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member loan inside a group facility.
 *
 * @mixin Loan
 */
class LoanMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'application_no' => $this->application_no,
            'loan_no' => $this->loan_no,
            'requested_amount' => (string) $this->requested_amount,
            'approved_amount' => $this->approved_amount !== null ? (string) $this->approved_amount : null,
            'installment_amount' => $this->installment_amount !== null ? (string) $this->installment_amount : null,
            'status' => $this->status->value,
            'days_past_due' => $this->days_past_due,
            'parties' => LoanPartyResource::collection($this->whenLoaded('parties')),
        ];
    }
}
