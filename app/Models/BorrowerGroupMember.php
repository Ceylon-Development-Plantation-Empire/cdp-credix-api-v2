<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GroupMemberRole;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BorrowerGroupMember extends Model
{
    use Audited;

    protected $fillable = [
        'borrower_group_id',
        'customer_id',
        'role',
        'joined_on',
        'left_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => GroupMemberRole::class,
            'joined_on' => 'date',
            'left_on' => 'date',
        ];
    }

    public function isCurrent(): bool
    {
        return $this->left_on === null;
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(BorrowerGroup::class, 'borrower_group_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
