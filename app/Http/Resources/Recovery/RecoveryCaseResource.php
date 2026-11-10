<?php

declare(strict_types=1);

namespace App\Http\Resources\Recovery;

use App\Models\RecoveryCase;
use App\Support\Mask;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The borrower's phone and contact information are shown in full to the agent working the case (or recovery managers),
 * masked for unauthorized users without sensitive-data or recovery-assign permissions.
 *
 * @mixin RecoveryCase
 */
class RecoveryCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $loan = $this->loan;
        $customer = $loan?->customer;
        $user = $request->user();

        // Permission check: assigned agent or recovery manager / super admin / sensitive data authorized
        $isAssignedAgent = $user && (int) $this->assigned_user_id === (int) $user->id;
        $canManageRecovery = $user && (
            $user->can('Recovery Case Assign') ||
            $user->can('Recovery Case Work') ||
            $user->hasRole('super_admin') ||
            Mask::canSeeSensitive()
        );
        $fullContact = $isAssignedAgent || $canManageRecovery;

        $guarantors = [];
        if ($loan && $loan->relationLoaded('guarantors')) {
            foreach ($loan->guarantors as $g) {
                $c = $g->relationLoaded('customer') ? $g->customer : null;
                $guarantors[] = [
                    'id' => $g->id,
                    'relationship' => $g->relationship_id ? (string) ($g->relationship ?? '') : null,
                    'name' => $c?->full_name ?? '—',
                    'id_number' => $c ? ($fullContact ? (string) $c->id_number : '—') : '—',
                    'phone' => $c ? ($fullContact ? (string) $c->phone_primary : PhoneNormalizer::mask($c->phone_primary)) : '—',
                    'secondary_phone' => ($fullContact && $c) ? (string) $c->phone_secondary : null,
                    'address' => ($fullContact && $c) ? trim("{$c->address_line_1}, {$c->city}") : ($c?->city ?? '—'),
                    'workplace' => ($fullContact && $c) ? ($c->employer_name ?? $c->business_name) : null,
                ];
            }
        }

        $collaterals = [];
        if ($loan && $loan->relationLoaded('collaterals')) {
            foreach ($loan->collaterals as $col) {
                $collaterals[] = [
                    'id' => $col->public_id,
                    'type' => $col->type,
                    'description' => $col->description,
                    'details' => $col->details,
                    'market_value' => (string) $col->market_value,
                    'forced_sale_value' => (string) $col->forced_sale_value,
                ];
            }
        }

        return [
            'public_id' => $this->public_id,
            'case_no' => $this->case_no,
            'stage' => $this->stage->value,
            'status' => $this->status->value,
            'can_see_sensitive' => $fullContact,
            'loan' => $loan ? [
                'public_id' => $loan->public_id,
                'loan_no' => $loan->loan_no,
                'application_no' => $loan->application_no,
                'product_name' => $loan->relationLoaded('product') ? $loan->product?->name : null,
                'sanctioned_amount' => (string) ($loan->approved_amount ?? $loan->requested_amount ?? 0),
                'installment_amount' => (string) ($loan->installment_amount ?? 0),
                'days_past_due' => $loan->days_past_due,
                'arrears_amount' => (string) $loan->arrears_amount,
                'outstanding_principal' => (string) $loan->outstanding_principal,
                'guarantors' => $guarantors,
                'collaterals' => $collaterals,
            ] : null,
            'customer' => $customer ? [
                'customer_no' => $customer->customer_no,
                'full_name' => $customer->full_name,
                'name_with_initials' => $customer->name_with_initials,
                'id_number' => $fullContact ? (string) $customer->id_number : ($customer->id_number ? substr((string) $customer->id_number, 0, 4) . '****' : null),
                'phone_primary' => $fullContact ? (string) $customer->phone_primary : PhoneNormalizer::mask($customer->phone_primary),
                'phone_secondary' => $fullContact ? (string) $customer->phone_secondary : null,
                'whatsapp_number' => $fullContact ? (string) $customer->whatsapp_number : null,
                'email' => $fullContact ? (string) $customer->email : Mask::email($customer->email),
                'address' => $fullContact
                    ? trim("{$customer->address_line_1}, " . ($customer->address_line_2 ? "{$customer->address_line_2}, " : '') . ($customer->landmark ? "Near {$customer->landmark}, " : '') . "{$customer->city}")
                    : (string) $customer->city,
                'address_line_1' => $fullContact ? (string) $customer->address_line_1 : null,
                'address_line_2' => $fullContact ? (string) $customer->address_line_2 : null,
                'landmark' => $fullContact ? (string) $customer->landmark : null,
                'city' => (string) $customer->city,
                'district' => (string) $customer->district,
                'employer_name' => $fullContact ? (string) $customer->employer_name : null,
                'business_name' => $fullContact ? (string) $customer->business_name : null,
                'occupation' => $fullContact ? (string) $customer->occupation : null,
                'employment_status' => $fullContact ? (string) ($customer->employment_status?->value ?? $customer->employment_status ?? '') : null,
            ] : null,
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'agent' => $this->whenLoaded('agent', fn () => $this->agent ? ['id' => $this->agent->id, 'name' => $this->agent->name] : null),
            'opened_dpd' => $this->opened_dpd,
            'opened_arrears' => (string) $this->opened_arrears,
            'opened_at' => $this->opened_at->toApiString(),
            'assigned_at' => $this->assigned_at?->toApiString(),
            'closed_at' => $this->closed_at?->toApiString(),
            'outcome' => $this->outcome,
        ];
    }
}
