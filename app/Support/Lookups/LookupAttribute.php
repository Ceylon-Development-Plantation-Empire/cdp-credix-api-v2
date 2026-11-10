<?php

declare(strict_types=1);

namespace App\Support\Lookups;

use App\Enums\LookupType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the accessor + mutator pair that exposes a lookup foreign key (e.g.
 * branches.branch_type_id) as its code (branch_type), so requests, responses, mass assignment and
 * the bulk import keep speaking codes while the table stores a real foreign key.
 *
 * DECISION: a standalone factory, not a model method: Eloquent treats every model method that
 * returns an Attribute as an accessor of its own.
 *
 * Usage in a model:
 *     protected function branchType(): Attribute
 *     {
 *         return LookupAttribute::for($this, LookupType::BranchType, 'branch_type_id', 'branch_type');
 *     }
 * plus 'branch_type' in $fillable / $appends and 'branch_type_id' in $hidden.
 */
final class LookupAttribute
{
    /**
     * @param  Model  $model  The model the attribute belongs to.
     * @param  LookupType  $type  The list the column points into.
     * @param  string  $column  Foreign-key column on the model.
     * @param  string  $field  API field name (used in the validation message).
     * @return Attribute<string|null, string|null>
     */
    public static function for(Model $model, LookupType $type, string $column, string $field): Attribute
    {
        return Attribute::make(
            // Drivers may return the key as a numeric string: normalise before comparing ids.
            get: function () use ($model, $type, $column) {
                $id = $model->getAttributes()[$column] ?? null;

                return app(LookupRegistry::class)->codeOf($type, $id === null ? null : (int) $id);
            },
            set: fn (?string $code) => [$column => app(LookupRegistry::class)->idFor($type, $code, $field)],
        );
    }
}
