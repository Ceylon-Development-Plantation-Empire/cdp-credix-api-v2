<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\BranchAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Limits every query of the model to the logged-in user's branch (see BranchAccess).
 *
 * Because it is a global scope it also covers show, update and every action that loads a
 * record by id, not only lists: a record of another branch simply "does not exist" (404) for
 * that user, so ids from other branches cannot be guessed or used. The old system only scoped
 * list endpoints.
 *
 * Use Model::withoutGlobalScope('branch') only in system code that has already authorised the
 * access (for example a nightly job), never with user input.
 */
trait BelongsToBranch
{
    protected static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $query) {
            $branchId = BranchAccess::restrictedBranchId();

            if ($branchId !== null) {
                $query->where($query->getModel()->getTable().'.branch_id', $branchId);
            }
        });
    }
}
