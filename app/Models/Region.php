<?php

declare(strict_types=1);

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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

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
