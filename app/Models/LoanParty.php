<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LookupType;
use App\Enums\PartyRole;
use App\Models\Concerns\Audited;
use App\Support\Lookups\LookupAttribute;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person on a loan and the role they play (borrower, co-borrower, group member, guarantor).
 *
 * relationship (guarantor ↔ borrower) is a code of the guarantor_relationship lookup list.
 *
 * @property int $id
 * @property int $loan_id
 * @property int $customer_id
 * @property PartyRole $role
 * @property string|null $relationship
 * @property Carbon|null $consented_at
 * @property Carbon|null $invoked_at
 * @property Carbon|null $released_at
 */
class LoanParty extends Model
{
    use Audited;

    /**
     * Columns whose change alone is not written to the audit trail (see Concerns\Audited).
     */
    public const AUDIT_IGNORED = ['open_loan_key'];

    /**
     * consented_at, consent_hash, invoked_at, released_at and open_loan_key are set by the loan
     * services only.
     *
     * @var list<string>
     */
    protected $fillable = [
        'loan_id',
        'customer_id',
        'role',
        'relationship',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'consent_hash',
        'open_loan_key',
        'relationship_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => PartyRole::class,
            'consented_at' => 'datetime',
            'invoked_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * relationship (code) ↔ relationship_id (lookups FK).
     *
     * @return Attribute<string|null, string|null>
     */
    protected function relationship(): Attribute
    {
        return LookupAttribute::for($this, LookupType::GuarantorRelationship, 'relationship_id', 'relationship');
    }

    /**
     * Whether the person has confirmed (offer accepted / guarantee agreed) by OTP.
     */
    public function hasConsented(): bool
    {
        return $this->consented_at !== null;
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
