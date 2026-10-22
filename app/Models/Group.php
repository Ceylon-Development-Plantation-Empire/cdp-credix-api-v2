<?php

declare(strict_types=1);

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
