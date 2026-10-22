<?php

declare(strict_types=1);

namespace App\Http\Resources\Customers;

use App\Enums\KycStatus;
use App\Models\Customer;
use App\Support\Mask;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer profile. Identity and contact details are shown in full only to users with the
 * "Customer View Sensitive" permission; everyone else sees them masked.
 *
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sensitive = Mask::canSeeSensitive();
        $phone = fn (?string $value) => $sensitive ? $value : PhoneNormalizer::mask($value);

        return [
            'public_id' => $this->public_id,
            'customer_no' => $this->customer_no,
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'identity' => [
                'id_type' => $this->id_type->value,
                'id_number' => $sensitive ? $this->id_number : Mask::idNumber($this->normalizedIdNumber()),
                'full_name' => $this->full_name,
                'name_with_initials' => $this->name_with_initials,
                'date_of_birth' => $sensitive ? $this->date_of_birth->toDateString() : null,
                'age' => $this->age(),
                'gender' => $this->gender?->value,
                'preferred_language' => $this->preferred_language->value,
            ],
            'contact' => [
                'phone_primary' => $phone($this->phone_primary),
                'phone_secondary' => $phone($this->phone_secondary),
                'whatsapp_number' => $phone($this->whatsapp_number),
                'email' => $sensitive ? $this->email : Mask::email($this->email),
            ],
            'address' => [
                'address_line_1' => $this->address_line_1,
                'address_line_2' => $this->address_line_2,
                'landmark' => $this->landmark,
                'city' => $this->city,
                'gn_division' => $this->gn_division,
                'ds_division' => $this->ds_division,
                'district' => $this->district,
                'province' => $this->province,
                'postal_code' => $this->postal_code,
            ],
            'employment' => [
                'status' => $this->employment_status->value,
                'occupation' => $this->occupation,
                'employer_name' => $this->employer_name,
                'employer_address' => $this->employer_address,
                'employer_phone' => $phone($this->employer_phone),
                'employer_email' => $sensitive ? $this->employer_email : Mask::email($this->employer_email),
                'business_name' => $this->business_name,
                'business_registration_no' => $this->business_registration_no,
                'business_nature' => $this->business_nature,
                'business_address' => $this->business_address,
                'business_phone' => $phone($this->business_phone),
                'business_email' => $sensitive ? $this->business_email : Mask::email($this->business_email),
            ],
            'kyc' => [
                // "verified" only while the confirmation is current and still matches the details.
                'status' => $this->hasValidKyc() ? KycStatus::Verified->value : ($this->kyc_status === KycStatus::Verified ? KycStatus::Expired->value : $this->kyc_status->value),
                'verified_at' => $this->kyc_verified_at?->toApiString(),
                'expires_at' => $this->kyc_expires_at?->toApiString(),
            ],
            'group' => $this->whenLoaded('currentGroupMembership', fn () => $this->currentGroupMembership ? [
                'public_id' => $this->currentGroupMembership->group->public_id,
                'group_no' => $this->currentGroupMembership->group->group_no,
                'name' => $this->currentGroupMembership->group->name,
                'role' => $this->currentGroupMembership->role->value,
            ] : null),
            'recommended_by' => $this->whenLoaded('recommendedBy', fn () => $this->recommendedBy ? [
                'id' => $this->recommendedBy->id,
                'name' => $this->recommendedBy->full_name,
            ] : null),
            'credit_score' => $this->credit_score,
            // Additive (guide v2.1): custom fields defined by the business.
            'meta' => $this->meta ?? (object) [],
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toApiString(),
            'updated_at' => $this->updated_at?->toApiString(),
        ];
    }
}
