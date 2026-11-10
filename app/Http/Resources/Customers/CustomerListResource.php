<?php

declare(strict_types=1);

namespace App\Http\Resources\Customers;

use App\Models\Customer;
use App\Support\Mask;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the customer list. ID and phone are always masked in lists.
 *
 * @mixin Customer
 */
class CustomerListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'customer_no' => $this->customer_no,
            'full_name' => $this->full_name,
            'id_type' => $this->id_type->value,
            'id_number' => Mask::idNumber($this->normalizedIdNumber()),
            'phone_primary' => PhoneNormalizer::mask($this->phone_primary),
            'email' => Mask::canSeeSensitive() ? $this->email : Mask::email($this->email),
            'credit_score' => $this->credit_score,
            'allow_multiple_loans' => (bool) $this->allow_multiple_loans,
            'max_open_loans' => (int) ($this->max_open_loans ?? 1),
            'multiple_loans_reason' => $this->multiple_loans_reason,
            'multiple_loans_approved_by' => $this->whenLoaded('multipleLoansApprovedBy', fn () => $this->multipleLoansApprovedBy ? [
                'id' => $this->multipleLoansApprovedBy->id,
                'name' => $this->multipleLoansApprovedBy->name,
            ] : null),
            'multiple_loans_approved_at' => $this->multiple_loans_approved_at?->toApiString(),
            'open_loan' => app(\App\Services\Loans\EligibilityService::class)->summary(app(\App\Services\Loans\EligibilityService::class)->openLoanOf($this->resource)),
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'kyc_status' => $this->hasValidKyc() ? 'verified' : $this->kyc_status->value,
            'is_active' => $this->is_active,
        ];
    }
}
