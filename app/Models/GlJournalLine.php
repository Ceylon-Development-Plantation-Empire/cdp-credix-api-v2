<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One debit or credit of a journal. Immutable like the journal once posted.
 */
class GlJournalLine extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'gl_journal_id',
        'gl_account_id',
        'debit',
        'credit',
        'memo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Journal lines cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Journal lines cannot be deleted.'));
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(GlJournal::class, 'gl_journal_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }
}
