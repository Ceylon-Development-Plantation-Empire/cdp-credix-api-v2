<?php

namespace App\Models;

use App\Enums\GroupMemberRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BorrowerGroupMember extends Model
{
    protected $fillable = [
        'borrower_group_id',
        'customer_id',
        'role',
        'joined_on',
        'left_on',
    ];

    protected $casts = [
        'role' => GroupMemberRole::class,
        'joined_on' => 'date',
        'left_on' => 'date',
    ];

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
