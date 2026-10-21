<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Enums\IdType;
use App\Enums\KycStatus;
use App\Enums\Language;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasReferenceNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToBranch, HasPublicId, HasReferenceNumber, SoftDeletes;

    public const REFERENCE_PREFIX = 'CUS';

    public const REFERENCE_COLUMN = 'customer_no';

    /**
     * The fields the customer confirms with the KYC OTP. If any of them changes afterwards the
     * confirmation no longer matches the record, so KYC goes back to pending.
     */
    public const KYC_FIELDS = [
        'id_type',
        'id_number_normalized',
        'full_name',
        'name_with_initials',
        'date_of_birth',
        'phone_primary',
        'address_line_1',
        'address_line_2',
        'city',
        'gn_division',
        'ds_division',
        'district',
        'province',
        'employment_status',
        'occupation',
        'employer_name',
        'business_name',
    ];

    /**
     * KYC columns, customer_no and id_number_normalized are not fillable; the services set them.
     */
    protected $fillable = [
        'branch_id',
        'id_type',
        'id_number',
        'full_name',
        'name_with_initials',
        'date_of_birth',
        'gender',
        'preferred_language',
        'phone_primary',
        'phone_secondary',
        'whatsapp_number',
        'email',
        'address_line_1',
        'address_line_2',
        'city',
        'gn_division',
        'ds_division',
        'district',
        'province',
        'postal_code',
        'employment_status',
        'occupation',
        'employer_name',
        'employer_address',
        'business_name',
        'business_registration_no',
        'recommended_by_employee_id',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'id_type' => IdType::class,
        'gender' => Gender::class,
        'preferred_language' => Language::class,
        'employment_status' => EmploymentStatus::class,
        'kyc_status' => KycStatus::class,
        'date_of_birth' => 'date',
        'kyc_verified_at' => 'datetime',
        'kyc_expires_at' => 'datetime',
        'is_active' => 'boolean',
        'credit_score' => 'integer',
    ];

    protected $attributes = [
        'kyc_status' => 'pending',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Search by name, customer number, phone or ID number. An ID number is matched on its
     * normalised form, so searching the old NIC finds a customer registered with the new one.
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $normalizedIds = array_filter(array_map(fn (IdType $type) => $type->normalize($search), IdType::cases()));
        // Phones are stored as +94XXXXXXXXX, so drop a leading 0 the user may type.
        $phoneDigits = ltrim(preg_replace('/\D+/', '', $search), '0');

        return $query->where(function (Builder $q) use ($search, $normalizedIds, $phoneDigits) {
            $q->where('full_name', 'like', "%{$search}%")
                ->orWhere('customer_no', $search);

            // Only search phones on a meaningful number of digits; a name has none and must not match everyone.
            if (strlen($phoneDigits) >= 4) {
                $q->orWhere('phone_primary', 'like', "%{$phoneDigits}%");
            }

            if ($normalizedIds) {
                $q->orWhereIn('id_number_normalized', $normalizedIds);
            }
        });
    }

    /**
     * Hash of the KYC fields as they are now. Compared with kyc_snapshot_hash to know whether
     * the confirmed details still match the record.
     */
    public function kycFingerprint(): string
    {
        return hash('sha256', json_encode($this->kycSnapshot()));
    }

    /**
     * The KYC fields as plain values, in a fixed order.
     *
     * @return array<string, mixed>
     */
    public function kycSnapshot(): array
    {
        $snapshot = [];

        foreach (self::KYC_FIELDS as $field) {
            $value = $this->getAttribute($field);
            $snapshot[$field] = match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                default => $value,
            };
        }

        return $snapshot;
    }

    /**
     * KYC counts only while it is verified, not expired, and still matches the current details.
     */
    public function hasValidKyc(): bool
    {
        return $this->kyc_status === KycStatus::Verified
            && $this->kyc_expires_at?->isFuture()
            && hash_equals((string) $this->kyc_snapshot_hash, $this->kycFingerprint());
    }

    public function age(): int
    {
        return (int) $this->date_of_birth->diffInYears(now());
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recommendedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recommended_by_employee_id');
    }

    public function financials(): HasMany
    {
        return $this->hasMany(CustomerFinancial::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CustomerBankAccount::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function groupMemberships(): HasMany
    {
        return $this->hasMany(BorrowerGroupMember::class);
    }

    public function currentGroupMembership(): HasOne
    {
        return $this->hasOne(BorrowerGroupMember::class)->whereNull('left_on');
    }
}
