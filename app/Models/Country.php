<?php

declare(strict_types=1);

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
