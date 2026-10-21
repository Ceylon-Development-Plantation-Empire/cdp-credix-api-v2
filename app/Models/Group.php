<?php

namespace App\Models;

use App\Enums\OrgUnitType;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends OrgUnit
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
        return OrgUnitType::Group;
    }

    /**
     * Get the branches associated with this group.
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
