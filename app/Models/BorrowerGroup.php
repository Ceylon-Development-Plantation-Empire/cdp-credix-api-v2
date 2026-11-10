<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BorrowerGroupStatus;
use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasReferenceNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BorrowerGroup extends Model
{
    use Audited;
    use BelongsToBranch, HasPublicId, HasReferenceNumber, SoftDeletes;

    public const REFERENCE_PREFIX = 'GRP';

    public const REFERENCE_COLUMN = 'group_no';

    protected $fillable = [
        'name',
        'branch_id',
        'formed_on',
        'meeting_day',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BorrowerGroupStatus::class,
            'formed_on' => 'date',
        ];
    }

    protected $attributes = [
        'status' => 'active',
    ];

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('group_no', $search);
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(BorrowerGroupMember::class);
    }

    public function currentMembers(): HasMany
    {
        return $this->hasMany(BorrowerGroupMember::class)->whereNull('left_on');
    }
}
