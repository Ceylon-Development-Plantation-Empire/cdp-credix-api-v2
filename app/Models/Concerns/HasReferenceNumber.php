<?php

namespace App\Models\Concerns;

/**
 * Human reference numbers (CUS-000123, GRP-000012 …) built from the row's own id.
 *
 * The id is unique and handed out by the database, so two records created at the same moment
 * can never get the same number. (The old system used "last id + 1", which collided under load.)
 *
 * The model defines REFERENCE_PREFIX and REFERENCE_COLUMN.
 */
trait HasReferenceNumber
{
    protected static function bootHasReferenceNumber(): void
    {
        static::created(function ($model) {
            if (empty($model->{static::REFERENCE_COLUMN})) {
                $model->{static::REFERENCE_COLUMN} = static::REFERENCE_PREFIX.'-'.str_pad((string) $model->getKey(), 6, '0', STR_PAD_LEFT);
                $model->saveQuietly();
            }
        });
    }
}
