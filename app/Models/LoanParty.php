<?php

namespace App\Models;

use App\Enums\PartyRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanParty extends Model
{
    /**
     * consented_at, consent_hash, invoked_at, released_at and open_loan_key are set by the
     * loan services only.
     */
    protected $fillable = [
        'loan_id',
        'customer_id',
        'role',
        'relationship',
    ];

    protected $casts = [
        'role' => PartyRole::class,
        'consented_at' => 'datetime',
        'invoked_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    protected $hidden = [
        'consent_hash',
        'open_loan_key',
    ];

    public function hasConsented(): bool
    {
        return $this->consented_at !== null;
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
