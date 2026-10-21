<?php

namespace App\Models;

use App\Enums\OrgUnitType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends OrgUnit
{
    protected $fillable = [
        'name',
        'code',
        'zonal_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function unitType(): OrgUnitType
    {
        return OrgUnitType::Region;
    }

    protected static function parentAttribute(): ?string
    {
        return 'zonal_id';
    }

    /**
     * Get the zonal associated with this region.
     */
    public function zonal(): BelongsTo
    {
        return $this->belongsTo(Zonal::class, 'parent_id');
    }

    /**
     * Get the branches associated with this region.
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
