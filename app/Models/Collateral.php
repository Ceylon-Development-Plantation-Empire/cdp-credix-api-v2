<?php

namespace App\Models;

use App\Enums\CollateralStatus;
use App\Enums\CollateralType;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Collateral extends Model
{
    use HasPublicId;

    /**
     * LTV, eligible amount and the status columns are set by CollateralService.
     */
    protected $fillable = [
        'loan_id',
        'type',
        'owner_customer_id',
        'description',
        'details',
        'market_value',
        'forced_sale_value',
        'valued_on',
        'valued_by',
        'created_by',
    ];

    protected $casts = [
        'type' => CollateralType::class,
        'status' => CollateralStatus::class,
        'details' => 'array',
        'market_value' => 'decimal:2',
        'forced_sale_value' => 'decimal:2',
        'ltv_percent' => 'decimal:2',
        'eligible_amount' => 'decimal:2',
        'valued_on' => 'date',
        'verified_at' => 'datetime',
        'perfected_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'proposed',
    ];

    /**
     * Collateral that still secures the loan.
     */
    public function isHeld(): bool
    {
        return in_array($this->status, [CollateralStatus::Proposed, CollateralStatus::Verified, CollateralStatus::Perfected], true);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'owner_customer_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
