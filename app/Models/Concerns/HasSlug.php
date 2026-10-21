<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Catalogue records (categories, products, document types) are addressed by a readable slug
 * instead of a numeric id or a hard-coded "code".
 *
 * When no slug is given it is made from the name; a number is appended if it is already taken
 * (vehicle-loan, vehicle-loan-2 …).
 */
trait HasSlug
{
    protected static function bootHasSlug(): void
    {
        static::creating(function ($model) {
            if (empty($model->slug)) {
                $model->slug = static::uniqueSlug($model->name);
            }
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $counter = 2;

        // withTrashed when available, so a soft-deleted record's slug is never reused.
        $query = fn () => method_exists(static::class, 'bootSoftDeletes') ? static::withTrashed() : static::query();

        while ($query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    public static function findBySlug(string $slug): ?static
    {
        return static::query()->where('slug', $slug)->first();
    }
}
