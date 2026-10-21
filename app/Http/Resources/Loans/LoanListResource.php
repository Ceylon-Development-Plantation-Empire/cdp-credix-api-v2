<?php

namespace App\Http\Resources\Loans;

use App\Models\Loan;
use App\Support\Mask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the loan list: only what the list screen shows.
 *
 * @mixin Loan
 */
class LoanListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'application_no' => $this->application_no,
            'loan_no' => $this->loan_no,
            'borrower_type' => $this->borrower_type->value,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'public_id' => $this->customer->public_id,
                'full_name' => $this->customer->full_name,
                'id_number' => Mask::idNumber($this->customer->id_number_normalized),
            ] : null),
            'borrower_group' => $this->whenLoaded('borrowerGroup', fn () => $this->borrowerGroup ? [
                'public_id' => $this->borrowerGroup->public_id,
                'group_no' => $this->borrowerGroup->group_no,
                'name' => $this->borrowerGroup->name,
            ] : null),
            'product' => $this->whenLoaded('product', fn () => ['slug' => $this->product->slug, 'name' => $this->product->name]),
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'amount' => $this->decisionAmount(),
            'status' => $this->status->value,
            'days_past_due' => $this->days_past_due,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
