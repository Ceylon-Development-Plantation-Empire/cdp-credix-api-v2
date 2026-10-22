<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LookupType;
use App\Models\Concerns\Audited;
use App\Support\Lookups\LookupAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A lending-margin plan: a named lending percentage for one collateral type, e.g.
 * "Property Mortgage Plan 2" lends up to 50 % of the property's value.
 *
 * Head office adds plans and changes their percentage at any time (LendingMarginPlanService). A
 * collateral valued under a plan keeps the percentage it got that day (collaterals.ltv_percent),
 * so a change applies to new pledges only. Plans are deactivated, never deleted, and the code of
 * a plan never changes.
 *
 * @property int $id
 * @property string $collateral_type
 * @property string $code
 * @property string $name
 * @property string $percentage
 * @property bool $is_active
 */
class LendingMarginPlan extends Model
{
    use Audited;

    /**
     * code is set once on create by the service; created_by / updated_by by the service.
     *
     * @var list<string>
     */
    protected $fillable = [
        'collateral_type',
        'name',
        'percentage',
        'description',
        'sort_order',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['collateral_type_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (LendingMarginPlan $plan) {
            // DECISION: collaterals point at the plan; moving it to another collateral type or recoding
            // it would silently relabel pledges already made. Add a new plan instead.
            if ($plan->isDirty(['code', 'collateral_type_id'])) {
                throw new LogicException('The code and collateral type of a lending-margin plan cannot change; add a new plan instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('Lending-margin plans are deactivated, never deleted.'));
    }

    /**
     * collateral_type (code) ↔ collateral_type_id (lookups FK).
     */
    protected function collateralType(): Attribute
    {
        return LookupAttribute::for($this, LookupType::CollateralType, 'collateral_type_id', 'collateral_type');
    }

    /**
     * @param  Builder<LendingMarginPlan>  $query
     * @return Builder<LendingMarginPlan>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function collaterals(): HasMany
    {
        return $this->hasMany(Collateral::class);
    }
}
