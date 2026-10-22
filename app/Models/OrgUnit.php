<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrgUnitType;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One level of the organisation structure (single-table inheritance on org_units).
 *
 * Each level is its own model (Country, Province, Zonal, Region, Group) that only ever sees its
 * own rows. The link to the level above is parent_id in the table but keeps its name in the API
 * (a province's "country_id", a region's "zonal_id" …), so screens and imports are unchanged.
 */
abstract class OrgUnit extends Model
{
    use Audited;
    use HasFactory;

    protected $table = 'org_units';

    /**
     * The level this model reads and writes.
     */
    abstract public static function unitType(): OrgUnitType;

    /**
     * API name of the link to the level above, or null for a top level.
     */
    protected static function parentAttribute(): ?string
    {
        return null;
    }

    protected static function booted(): void
    {
        static::addGlobalScope('unit_type', fn (Builder $query) => $query->where('org_units.unit_type', static::unitType()->value));
        static::creating(fn (OrgUnit $unit) => $unit->unit_type = static::unitType()->value);
    }

    public function getAttribute($key)
    {
        return parent::getAttribute($key === static::parentAttribute() ? 'parent_id' : $key);
    }

    public function setAttribute($key, $value)
    {
        return parent::setAttribute($key === static::parentAttribute() ? 'parent_id' : $key, $value);
    }

    public function toArray(): array
    {
        $data = parent::toArray();

        if ($parent = static::parentAttribute()) {
            $data[$parent] = $this->parent_id;
        }

        unset($data['unit_type'], $data['parent_id']);

        return $data;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to search by name, code or description.
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }
}
