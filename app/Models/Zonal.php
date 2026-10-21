<?php

namespace App\Models;

use App\Enums\OrgUnitType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zonal extends OrgUnit
{
    protected $fillable = [
        'name',
        'code',
        'province_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function unitType(): OrgUnitType
    {
        return OrgUnitType::Zone;
    }

    protected static function parentAttribute(): ?string
    {
        return 'province_id';
    }

    /**
     * Get the province associated with this zonal.
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'parent_id');
    }

    /**
     * Get the regions associated with this zonal.
     */
    public function regions(): HasMany
    {
        return $this->hasMany(Region::class, 'parent_id');
    }
}
