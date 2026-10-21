<?php

namespace App\Services\Customers;

use App\Enums\IdType;
use App\Enums\KycStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\User;
use App\Support\BranchAccess;
use App\Support\NicNormalizer;
use App\Support\PhoneNormalizer;
use App\Traits\ActivityLogTrait;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    use ActivityLogTrait;

    /**
     * Customers must be adults; this is a legal minimum for a contract, separate from the
     * borrower age rules that apply when a loan is assessed.
     */
    protected const MINIMUM_AGE = 18;

    protected const PHONE_FIELDS = ['phone_primary', 'phone_secondary', 'whatsapp_number'];

    /**
     * @param  array<string, mixed>  $data  Validated CreateCustomerRequest data.
     */
    public function create(array $data, User $user): Customer
    {
        return DB::transaction(function () use ($data, $user) {
            $this->assertBranchAllowed((int) $data['branch_id'], $user);

            [$attributes, $normalizedId] = $this->prepare($data);
            $this->assertNotRegistered(IdType::from($attributes['id_type']), $normalizedId);

            $customer = new Customer($attributes + ['created_by' => $user->id, 'updated_by' => $user->id]);
            $customer->id_number_normalized = $normalizedId;
            $customer->save();

            return $customer->refresh();
        });
    }

    /**
     * Update a customer. If anything the customer confirmed for KYC changes, the old confirmation
     * no longer describes the record, so KYC drops back to pending and must be confirmed again.
     *
     * @param  array<string, mixed>  $data  Validated UpdateCustomerRequest data.
     */
    public function update(Customer $customer, array $data, User $user): Customer
    {
        return DB::transaction(function () use ($customer, $data, $user) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if (isset($data['branch_id']) && (int) $data['branch_id'] !== $customer->branch_id) {
                $this->assertBranchAllowed((int) $data['branch_id'], $user);
            }

            // Fill the identity fields we did not receive from the current record, so the NIC and
            // date of birth are always checked together.
            $merged = array_merge([
                'id_type' => $customer->id_type->value,
                'id_number' => $customer->id_number,
                'date_of_birth' => $customer->date_of_birth->toDateString(),
                'gender' => $customer->gender?->value,
            ], $data);

            [$attributes, $normalizedId] = $this->prepare($merged);

            if ($normalizedId !== $customer->id_number_normalized || $attributes['id_type'] !== $customer->id_type->value) {
                $this->assertNotRegistered(IdType::from($attributes['id_type']), $normalizedId, $customer->id);
            }

            $wasVerified = $customer->kyc_status === KycStatus::Verified;

            $customer->fill(array_intersect_key($attributes, $data + array_flip(['id_type', 'id_number', 'date_of_birth', 'gender'])));
            $customer->id_number_normalized = $normalizedId;
            $customer->updated_by = $user->id;

            if ($wasVerified && $customer->kycFingerprint() !== $customer->kyc_snapshot_hash) {
                $customer->kyc_status = KycStatus::Pending;

                $this->logActivity('KYC_RESET', 'Customer', "KYC reset to pending for {$customer->customer_no}: confirmed details changed");
            }

            $customer->save();

            return $customer->refresh();
        });
    }

    /**
     * Find an existing customer by ID document, in any branch, so staff can see that a person is
     * already registered before creating a duplicate. Only the customer number and branch are
     * revealed for customers outside the user's branch.
     *
     * @return array<string, mixed>|null
     */
    public function lookup(IdType $type, string $idNumber): ?array
    {
        $normalized = $type->normalize($idNumber);

        if ($normalized === null) {
            return null;
        }

        $customer = Customer::withoutGlobalScope('branch')->with('branch:id,name')
            ->where('id_type', $type->value)->where('id_number_normalized', $normalized)->first();

        if (! $customer) {
            return null;
        }

        return [
            'customer_no' => $customer->customer_no,
            'branch' => $customer->branch?->name,
            'public_id' => BranchAccess::canAccessBranch($customer->branch_id) ? $customer->public_id : null,
        ];
    }

    /**
     * Normalise identity and phone fields and cross-check the NIC against date of birth and gender.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: string}
     */
    protected function prepare(array $data): array
    {
        $type = IdType::from($data['id_type']);
        $normalized = $type->normalize($data['id_number']);
        $dateOfBirth = CarbonImmutable::parse($data['date_of_birth']);

        if ($type === IdType::Nic) {
            // The NIC encodes the date of birth and gender; a mismatch means one of them was typed wrong.
            $nicBirthDate = NicNormalizer::birthDate($normalized);

            if ($nicBirthDate && ! $nicBirthDate->isSameDay($dateOfBirth)) {
                throw new BusinessRuleException('NIC_DOB_MISMATCH', "The date of birth does not match the NIC (the NIC says {$nicBirthDate->toDateString()}).", 422);
            }

            $nicGender = NicNormalizer::gender($normalized);

            if (! empty($data['gender']) && $data['gender'] !== $nicGender) {
                throw new BusinessRuleException('NIC_GENDER_MISMATCH', 'The gender does not match the NIC.', 422);
            }

            $data['gender'] = $nicGender;
        }

        if ($dateOfBirth->diffInYears(now()) < self::MINIMUM_AGE) {
            throw new BusinessRuleException('CUSTOMER_UNDER_AGE', 'A customer must be at least '.self::MINIMUM_AGE.' years old.', 422);
        }

        foreach (self::PHONE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = PhoneNormalizer::normalize($data[$field]);
            }
        }

        if (! empty($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }

        $data['id_number'] = strtoupper(trim($data['id_number']));

        return [$data, $normalized];
    }

    /**
     * One person, one record — across all branches and including deactivated (soft deleted) ones.
     */
    protected function assertNotRegistered(IdType $type, string $normalized, ?int $ignoreId = null): void
    {
        $existing = Customer::withoutGlobalScope('branch')->withTrashed()
            ->where('id_type', $type->value)
            ->where('id_number_normalized', $normalized)
            ->when($ignoreId, fn ($q, $id) => $q->whereKeyNot($id))
            ->first();

        if ($existing) {
            throw new BusinessRuleException('CUSTOMER_ALREADY_EXISTS', 'A customer with this ID number is already registered.', 409, [
                'customer_no' => $existing->customer_no,
            ]);
        }
    }

    protected function assertBranchAllowed(int $branchId, User $user): void
    {
        if (! BranchAccess::canAccessBranch($branchId, $user)) {
            throw new BusinessRuleException('BRANCH_NOT_ALLOWED', 'You can only register customers in your own branch.', 403);
        }
    }
}
