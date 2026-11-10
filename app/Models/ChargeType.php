<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChargeBehaviour;
use App\Enums\ChargeCalculation;
use App\Enums\ChargeRecurrence;
use App\Support\Charges\ChargeTypeRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A fee or charge type managed by the business (see the charge_types migration).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $display_group
 * @property ChargeBehaviour $behaviour
 * @property list<string> $allowed_calculations
 * @property list<string> $allowed_recurrences
 * @property bool $is_system
 * @property bool $is_active
 */
class ChargeType extends Model
{
    /**
     * code and behaviour are set on create only (ChargeTypeService); is_system only by the seeder.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'display_group',
        'behaviour',
        'allowed_calculations',
        'allowed_recurrences',
        'description',
        'is_active',
        'sort_order',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'behaviour' => ChargeBehaviour::class,
            'allowed_calculations' => 'array',
            'allowed_recurrences' => 'array',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Protect the identity of a type that products and ledger rows already reference.
     */
    protected static function booted(): void
    {
        static::updating(function (ChargeType $type) {
            if ($type->isDirty(['code', 'behaviour'])) {
                throw new LogicException('The code and behaviour of a charge type cannot change; add a new type instead.');
            }
            if ($type->is_system && $type->isDirty('is_active') && ! $type->is_active) {
                throw new LogicException('A system charge type cannot be deactivated.');
            }
        });
        static::deleting(fn () => throw new LogicException('Charge types are deactivated, never deleted.'));
        static::saved(fn () => app(ChargeTypeRegistry::class)->forget());
    }

    /**
     * Calculations a product may use with this type.
     *
     * @return list<ChargeCalculation>
     */
    public function calculations(): array
    {
        return array_map(fn (string $value) => ChargeCalculation::from($value), $this->allowed_calculations);
    }

    /**
     * Recurrences a product may use with this type.
     *
     * @return list<ChargeRecurrence>
     */
    public function recurrences(): array
    {
        return array_map(fn (string $value) => ChargeRecurrence::from($value), $this->allowed_recurrences);
    }

    /**
     * @param  Builder<ChargeType>  $query
     * @return Builder<ChargeType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return HasMany<ProductCharge, $this>
     */
    public function productCharges(): HasMany
    {
        return $this->hasMany(ProductCharge::class);
    }
}
