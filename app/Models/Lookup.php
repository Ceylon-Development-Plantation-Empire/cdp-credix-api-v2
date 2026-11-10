<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LookupType;
use App\Support\BusinessDate;
use App\Support\Lookups\LookupRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One value of a business-editable code list (see the lookups migration and App\Enums\LookupType).
 *
 * Records point at a value by id (real foreign key, RESTRICT), the API by its code. A value in use
 * is deactivated or end-dated, never deleted.
 *
 * @property int $id
 * @property LookupType $type
 * @property string $code
 * @property string $label
 * @property array<string, mixed>|null $meta
 * @property bool $is_active
 * @property bool $is_system
 */
class Lookup extends Model
{
    /**
     * Code and type are only set on create (LookupService); a used code never changes.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'code',
        'label',
        'description',
        'meta',
        'sort_order',
        'is_active',
        'is_system',
        'effective_from',
        'effective_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LookupType::class,
            'meta' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * Keep the registry cache in step and protect the stable code of a value.
     */
    protected static function booted(): void
    {
        static::updating(function (Lookup $lookup) {
            // DECISION: records and the API refer to the code; changing it would silently relabel history.
            if ($lookup->isDirty(['type', 'code'])) {
                throw new LogicException('The code of a lookup value cannot change; add a new value instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('Lookup values are deactivated, never deleted.'));
        static::saved(fn (Lookup $lookup) => app(LookupRegistry::class)->forget($lookup->type));
    }

    /**
     * Values of one list.
     *
     * @param  Builder<Lookup>  $query
     * @return Builder<Lookup>
     */
    public function scopeOfType(Builder $query, LookupType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * Values that may be chosen today: active and inside their effective period.
     *
     * @param  Builder<Lookup>  $query
     * @return Builder<Lookup>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        $today = BusinessDate::today()->toDateString();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today));
    }
}
