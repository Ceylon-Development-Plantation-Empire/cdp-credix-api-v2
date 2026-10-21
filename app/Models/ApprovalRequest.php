<?php

namespace App\Models;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Models\Concerns\HasPublicId;
use App\Support\BranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRequest extends Model
{
    use HasPublicId;

    /**
     * Status and decision columns are not fillable; ApprovalRequestService sets them.
     */
    protected $fillable = [
        'subject_type',
        'subject_id',
        'branch_id',
        'action',
        'payload',
        'reason',
        'requested_by',
    ];

    protected $casts = [
        'action' => ApprovalAction::class,
        'status' => ApprovalStatus::class,
        'payload' => 'array',
        'decided_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Branch staff see the requests of their own branch (loans, payments) plus head-office
     * requests that belong to no branch (products). Head office sees everything (BranchAccess).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('branch', function (Builder $query) {
            $branchId = BranchAccess::restrictedBranchId();

            if ($branchId !== null) {
                $query->where(fn (Builder $q) => $q->where('approval_requests.branch_id', $branchId)->orWhereNull('approval_requests.branch_id'));
            }
        });
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
