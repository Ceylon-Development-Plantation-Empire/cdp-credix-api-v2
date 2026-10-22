<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoanActivityType;
use App\Enums\LoanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One entry of a loan's timeline. Written once and never edited: the maker-checker rules rely on it.
 */
class LoanActivity extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'loan_id',
        'recovery_case_id',
        'type',
        'from_status',
        'to_status',
        'remarks',
        'promised_amount',
        'promised_date',
        'metadata',
        'performed_by',
        'performed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LoanActivityType::class,
            'from_status' => LoanStatus::class,
            'to_status' => LoanStatus::class,
            'promised_amount' => 'decimal:2',
            'promised_date' => 'date',
            'metadata' => 'array',
            'performed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Loan activities cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Loan activities cannot be deleted.'));
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
