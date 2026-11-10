<?php

declare(strict_types=1);

namespace App\Http\Resources\Loans;

use App\Models\LoanParty;
use App\Support\Mask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One guarantee in the guarantor list: the guarantor, whom they stand for and on which loan.
 *
 * @mixin LoanParty
 */
class GuarantorListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => [
                'public_id' => $this->customer->public_id,
                'customer_no' => $this->customer->customer_no,
                'full_name' => $this->customer->full_name,
                'id_number' => Mask::idNumber($this->customer->normalizedIdNumber()),
            ],
            'relationship' => $this->relationship,
            'consented_at' => $this->consented_at?->toApiString(),
            'invoked_at' => $this->invoked_at?->toApiString(),
            'released_at' => $this->released_at?->toApiString(),
            'loan' => [
                'public_id' => $this->loan->public_id,
                'application_no' => $this->loan->application_no,
                'loan_no' => $this->loan->loan_no,
                'status' => $this->loan->status->value,
                'amount' => $this->loan->decisionAmount(),
                'borrower' => $this->loan->customer ? [
                    'public_id' => $this->loan->customer->public_id,
                    'full_name' => $this->loan->customer->full_name,
                ] : null,
            ],
            'created_at' => $this->created_at?->toApiString(),
        ];
    }
}
