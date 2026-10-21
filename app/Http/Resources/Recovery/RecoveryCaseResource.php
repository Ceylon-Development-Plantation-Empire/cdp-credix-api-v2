<?php

namespace App\Http\Resources\Recovery;

use App\Models\RecoveryCase;
use App\Support\Mask;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The borrower's phone is shown in full to the agent working the case (they must call), masked
 * for everyone else without the sensitive-data permission.
 *
 * @mixin RecoveryCase
 */
class RecoveryCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $loan = $this->loan;
        $customer = $loan?->customer;
        $fullContact = $request->user()?->id === $this->assigned_user_id || Mask::canSeeSensitive();

        return [
            'public_id' => $this->public_id,
            'case_no' => $this->case_no,
            'stage' => $this->stage->value,
            'status' => $this->status->value,
            'loan' => $loan ? [
                'public_id' => $loan->public_id,
                'loan_no' => $loan->loan_no,
                'days_past_due' => $loan->days_past_due,
                'arrears_amount' => (string) $loan->arrears_amount,
                'outstanding_principal' => (string) $loan->outstanding_principal,
            ] : null,
            'customer' => $customer ? [
                'customer_no' => $customer->customer_no,
                'full_name' => $customer->full_name,
                'phone_primary' => $fullContact ? $customer->phone_primary : PhoneNormalizer::mask($customer->phone_primary),
                'address' => $fullContact ? trim("{$customer->address_line_1}, {$customer->city}") : $customer->city,
            ] : null,
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'agent' => $this->whenLoaded('agent', fn () => $this->agent ? ['id' => $this->agent->id, 'name' => $this->agent->name] : null),
            'opened_dpd' => $this->opened_dpd,
            'opened_arrears' => (string) $this->opened_arrears,
            'opened_at' => $this->opened_at->toIso8601String(),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'outcome' => $this->outcome,
        ];
    }
}
