<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GlAccountType;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An account of the chart of accounts. System accounts (with a slug) are the ones the posting
 * rules write to: they can be renamed and renumbered but never retyped or deactivated.
 */
class GlAccount extends Model
{
    use Audited;

    protected $fillable = [
        'code',
        'name',
        'type',
        'description',
        'is_active',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => GlAccountType::class,
            'is_active' => 'boolean',
        ];
    }

    public function isSystem(): bool
    {
        return $this->slug !== null;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GlJournalLine::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
