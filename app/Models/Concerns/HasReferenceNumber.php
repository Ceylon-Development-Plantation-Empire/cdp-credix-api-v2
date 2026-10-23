<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Human reference numbers (CUS-000123, GRP-000012 …) built from the row's own id.
 *
 * The id is unique and handed out by the database, so two records created at the same moment
 * can never get the same number. (The old system used "last id + 1", which collided under load.)
 *
 * The model defines the constants REFERENCE_PREFIX and REFERENCE_COLUMN (read by name below, so the trait
 * does not depend on them being declared where an editor can see them).
 *
 * @method static void created(\Closure|string $callback) Eloquent's model event, provided by the model using this trait.
 */
trait HasReferenceNumber
{
    protected static function bootHasReferenceNumber(): void
    {
        static::created(function ($model) {
            $column = constant(static::class.'::REFERENCE_COLUMN');

            if (empty($model->{$column})) {
                $model->{$column} = method_exists($model, 'generateReferenceNumber')
                    ? $model->generateReferenceNumber()
                    : constant(static::class.'::REFERENCE_PREFIX').'-'.str_pad((string) $model->getKey(), 6, '0', STR_PAD_LEFT);
                $model->saveQuietly();
            }
        });
    }
}
