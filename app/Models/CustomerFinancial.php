<?php

namespace App\Models;

use App\Enums\FinancialKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerFinancial extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_id',
        'kind',
        'category',
        'description',
        'institution_name',
        'reference_no',
        'amount',
        'monthly_obligation',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'kind' => FinancialKind::class,
        'amount' => 'decimal:2',
        'monthly_obligation' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
