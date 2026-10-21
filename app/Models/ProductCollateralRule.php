<?php

namespace App\Models;

use App\Enums\CollateralType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCollateralRule extends Model
{
    protected $fillable = [
        'product_id',
        'collateral_type',
        'max_ltv_percent',
        'is_mandatory',
    ];

    protected $casts = [
        'collateral_type' => CollateralType::class,
        'max_ltv_percent' => 'decimal:2',
        'is_mandatory' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
