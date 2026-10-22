<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LookupType;
use App\Models\Concerns\Audited;
use App\Support\Lookups\LookupAttribute;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A collateral type a product accepts, with the lending % used when no lending-margin plan is chosen.
 *
 * @property string $collateral_type
 */
class ProductCollateralRule extends Model
{
    use Audited;

    protected $fillable = [
        'product_id',
        'collateral_type',
        'max_ltv_percent',
        'is_mandatory',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_ltv_percent' => 'decimal:2',
            'is_mandatory' => 'boolean',
        ];
    }

    /**
     * @var list<string>
     */
    protected $hidden = ['collateral_type_id'];

    /**
     * collateral_type (code) ↔ collateral_type_id (lookups FK).
     */
    protected function collateralType(): Attribute
    {
        return LookupAttribute::for($this, LookupType::CollateralType, 'collateral_type_id', 'collateral_type');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
