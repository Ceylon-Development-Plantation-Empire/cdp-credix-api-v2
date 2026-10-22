<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllocationComponent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of a ledger transaction. Immutable like the transaction itself.
 */
class LoanAllocation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'loan_transaction_id',
        'loan_installment_id',
        'component',
        'amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'component' => AllocationComponent::class,
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Allocations cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Allocations cannot be deleted.'));
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LoanTransaction::class, 'loan_transaction_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(LoanInstallment::class, 'loan_installment_id');
    }
}
