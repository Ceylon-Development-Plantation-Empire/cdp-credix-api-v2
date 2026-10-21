<?php

namespace App\Models;

use App\Enums\ChargeType;
use App\Enums\PaymentChannel;
use App\Enums\TransactionType;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasReferenceNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * A ledger row. Immutable: corrections are reversal transactions, never edits.
 */
class LoanTransaction extends Model
{
    use HasPublicId, HasReferenceNumber;

    public const REFERENCE_PREFIX = 'TXN';

    public const REFERENCE_COLUMN = 'txn_no';

    public const UPDATED_AT = null;

    protected $fillable = [
        'loan_id',
        'type',
        'charge_type',
        'amount',
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

    protected $casts = [
        'type' => TransactionType::class,
        'charge_type' => ChargeType::class,
        'channel' => PaymentChannel::class,
        'amount' => 'decimal:2',
        'value_date' => 'date',
        'is_charity' => 'boolean',
    ];

    protected $hidden = [
        'idempotency_key',
    ];

    protected static function booted(): void
    {
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
