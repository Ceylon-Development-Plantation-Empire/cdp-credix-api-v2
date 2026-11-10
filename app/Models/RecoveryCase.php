<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecoveryStage;
use App\Enums\RecoveryStatus;
use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasReferenceNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecoveryCase extends Model
{
    use Audited;
    use BelongsToBranch, HasPublicId, HasReferenceNumber;

    public const REFERENCE_PREFIX = 'REC';

    public const REFERENCE_COLUMN = 'case_no';

    /**
     * Stage, status, assignment and the open key are set by RecoveryService only.
     */
    protected $fillable = [
        'loan_id',
        'branch_id',
        'opened_dpd',
        'opened_arrears',
        'opened_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => RecoveryStage::class,
            'status' => RecoveryStatus::class,
            'opened_dpd' => 'integer',
            'opened_arrears' => 'decimal:2',
            'opened_at' => 'datetime',
            'assigned_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'open_case_key',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', RecoveryStatus::Open->value);
    }

    public function isOpen(): bool
    {
        return $this->status === RecoveryStatus::Open;
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LoanActivity::class);
    }
}
