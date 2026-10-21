<?php

namespace App\Models;

use App\Enums\GlJournalSource;
use App\Enums\GlJournalStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasReferenceNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * A balanced double entry. Once posted it is never changed or deleted; the only change allowed
 * is the decision on a pending manual journal (posted or rejected).
 */
class GlJournal extends Model
{
    use BelongsToBranch, HasPublicId, HasReferenceNumber;

    public const REFERENCE_PREFIX = 'JV';

    public const REFERENCE_COLUMN = 'journal_no';

    protected $fillable = [
        'source',
        'source_key',
        'entry_date',
        'value_date',
        'branch_id',
        'loan_id',
        'loan_transaction_id',
        'narration',
        'total',
        'status',
        'created_by',
    ];

    protected $casts = [
        'source' => GlJournalSource::class,
        'status' => GlJournalStatus::class,
        'entry_date' => 'date',
        'value_date' => 'date',
        'total' => 'decimal:2',
        'posted_at' => 'datetime',
    ];

    protected $hidden = [
        'source_key',
    ];

    protected static function booted(): void
    {
        static::updating(function (GlJournal $journal) {
            $setsNumber = array_keys($journal->getDirty()) === [self::REFERENCE_COLUMN] && $journal->getOriginal(self::REFERENCE_COLUMN) === null;

            if (! $setsNumber && $journal->getOriginal('status') !== GlJournalStatus::Pending) {
                throw new LogicException('Posted journals cannot be changed. Post a correcting journal instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('Journals cannot be deleted.'));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GlJournalLine::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LoanTransaction::class, 'loan_transaction_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'subject');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('gl_journals.status', GlJournalStatus::Posted->value);
    }
}
