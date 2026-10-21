<?php

namespace App\Models;

use App\Enums\ChargeCalculation;
use App\Enums\ChargeCollection;
use App\Enums\ChargeRecurrence;
use App\Enums\ChargeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCharge extends Model
{
    protected $fillable = [
        'product_id',
        'charge_type',
        'calculation',
        'value',
        'min_amount',
        'max_amount',
        'collection',
        'recurrence',
        'is_charity',
        'is_active',
    ];

    protected $casts = [
        'charge_type' => ChargeType::class,
        'calculation' => ChargeCalculation::class,
        'collection' => ChargeCollection::class,
        'recurrence' => ChargeRecurrence::class,
        'value' => 'decimal:4',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'is_charity' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
