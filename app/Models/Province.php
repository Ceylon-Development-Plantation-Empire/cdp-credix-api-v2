<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrgUnitType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends OrgUnit
{
    protected $fillable = [
        'name',
        'code',
        'country_id',
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
        return OrgUnitType::Province;
    }

    protected static function parentAttribute(): ?string
    {
        return 'country_id';
    }

    /**
     * Get the country associated with this province.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'parent_id');
    }

    /**
     * Get the zonals associated with this province.
     */
    public function zonals(): HasMany
    {
        return $this->hasMany(Zonal::class, 'parent_id');
    }
}
