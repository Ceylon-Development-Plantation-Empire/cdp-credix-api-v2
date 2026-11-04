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
    protected static function booted(): void
    {
        static::creating(function (GlAccount $account) {
            if (empty($account->code)) {
                $account->code = static::nextCode($account->type);
            }
        });
    }

    public static function nextCode(GlAccountType|string|null $type = null): string
    {
        $typeEnum = $type instanceof GlAccountType ? $type : ($type ? GlAccountType::tryFrom($type) : null);
        $base = match ($typeEnum) {
            GlAccountType::Asset => 1000,
            GlAccountType::Liability => 2000,
            GlAccountType::Equity => 3000,
            GlAccountType::Income => 4000,
            GlAccountType::Expense => 5000,
            default => 1000,
        };

        $prefix = (string) substr((string) $base, 0, 1);
        $maxCode = static::where('code', 'like', $prefix . '%')
            ->whereRaw('code REGEXP "^[0-9]+$"')
            ->max('code');

        $next = $maxCode ? ((int) $maxCode + 1) : ($base + 1);

        while (static::where('code', (string) $next)->exists()) {
            $next++;
        }

        return (string) $next;
    }

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
