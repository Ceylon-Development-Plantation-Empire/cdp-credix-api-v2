<?php

namespace App\Models;

use App\Enums\OrgUnitType;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends OrgUnit
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function unitType(): OrgUnitType
    {
        return OrgUnitType::Country;
    }

    /**
     * Get the provinces of this country.
     */
    public function provinces(): HasMany
    {
        return $this->hasMany(Province::class, 'parent_id');
    }
}
