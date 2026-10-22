<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentChannel;
use App\Enums\TransactionType;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasReferenceNumber;
use App\Support\Charges\ChargeTypeRegistry;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * A row of the loan sub-ledger.
 *
 * SECURITY / integrity: immutable. Corrections are reversal transactions, never edits; the only
 * write after the insert is the reference number. balance_after (what the borrower owed right
 * after this row) is required on every insert and computed by the posting service.
 *
 * charge_type is the code of a charge_types row, stored as charge_type_id.
 *
 * @property int $id
 * @property string $public_id
 * @property string|null $txn_no
 * @property int $loan_id
 * @property TransactionType $type
 * @property string|null $charge_type
 * @property string $amount
 * @property string $balance_after
 * @property PaymentChannel $channel
 */
class LoanTransaction extends Model
{
    use HasPublicId, HasReferenceNumber;

    public const REFERENCE_PREFIX = 'TXN';

    public const REFERENCE_COLUMN = 'txn_no';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'loan_id',
        'type',
        'charge_type',
        'charge_type_id',
        'amount',
        'balance_after',
        'value_date',
        'channel',
        'external_reference',
        'payer_customer_id',
        'idempotency_key',
        'reverses_transaction_id',
        'narration',
        'is_charity',
        'posted_by',
        'approved_by',
    ];

    /**
     * @var array<string, string>
     */
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'channel' => PaymentChannel::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'value_date' => 'date',
            'is_charity' => 'boolean',
        ];
    }

    /**
     * @var list<string>
     */
    protected $hidden = [
        'idempotency_key',
        'charge_type_id',
        'ip_address',
    ];

    /**
     * charge_type (code) ↔ charge_type_id.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function chargeType(): Attribute
    {
        return Attribute::make(
            get: fn () => app(ChargeTypeRegistry::class)->findById(isset($this->attributes['charge_type_id']) ? (int) $this->attributes['charge_type_id'] : null)?->code,
            set: fn (?string $code) => ['charge_type_id' => app(ChargeTypeRegistry::class)->find($code)?->id],
        );
    }

    protected static function booted(): void
    {
        static::creating(function (LoanTransaction $txn) {
            if ($txn->balance_after === null) {
                throw new LogicException('A loan transaction needs balance_after (computed by the posting service).');
            }
            // Source of the request for the audit trail; null for nightly jobs and other console work.
            $txn->ip_address ??= request()?->ip();
        });

        // The only change ever allowed is the reference number written right after the insert.
        static::updating(function (LoanTransaction $txn) {
            if (array_keys($txn->getDirty()) !== [self::REFERENCE_COLUMN] || $txn->getOriginal(self::REFERENCE_COLUMN) !== null) {
                throw new LogicException('Loan transactions cannot be changed. Post a reversal instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('Loan transactions cannot be deleted. Post a reversal instead.'));
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'payer_customer_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LoanAllocation::class);
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }

    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'subject');
    }
}
