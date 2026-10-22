<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SettingType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'group',
        'type',
        'value',
        'rules',
        'label',
        'description',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
            'rules' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Settings are read on almost every request (grace days, limits …), so they are cached.
        // Any write clears the cached copy of that key so the new value is used straight away.
        static::saved(fn (Setting $setting) => Cache::forget(self::cacheKey($setting->key)));
        static::deleted(fn (Setting $setting) => Cache::forget(self::cacheKey($setting->key)));
    }

    /**
     * Whether "someone else must do this step" rules apply (loan review/verify/approve/disburse,
     * documents, collateral, approval requests). Off by default: the permission alone decides who can act.
     */
    public static function segregationEnforced(): bool
    {
        return (bool) self::value('approval.segregation_enabled', false);
    }

    /**
     * Read a setting as its typed PHP value.
     *
     * Usage: Setting::value('guarantor.max_active_guarantees', 2)
     */
    public static function value(string $key, mixed $default = null): mixed
    {
        $setting = Cache::rememberForever(self::cacheKey($key), function () use ($key) {
            // Cache an array (not the model) so a missing key is cached too and does not hit the DB every time.
            $row = static::query()->where('key', $key)->first(['type', 'value']);

            return $row ? ['type' => $row->type->value, 'value' => $row->value] : ['type' => null, 'value' => null];
        });

        if ($setting['type'] === null || $setting['value'] === null) {
            return $default;
        }

        return SettingType::from($setting['type'])->cast($setting['value']);
    }

    public static function cacheKey(string $key): string
    {
        return "setting:{$key}";
    }

    /**
     * The value cast to its type, for API responses.
     */
    public function typedValue(): mixed
    {
        return $this->type->cast($this->value);
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('key', 'like', "%{$search}%")
                ->orWhere('label', 'like', "%{$search}%");
        });
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
