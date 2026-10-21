<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Sensitive records get a random ULID (public_id) that is used in URLs and responses,
 * so nobody can walk through /loans/1, /loans/2 … The numeric id stays internal for joins.
 */
trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }

    public static function findByPublicId(string $publicId): ?static
    {
        return static::query()->where('public_id', $publicId)->first();
    }
}
