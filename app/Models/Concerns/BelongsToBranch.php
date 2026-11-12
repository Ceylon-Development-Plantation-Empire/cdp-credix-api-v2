<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\BranchAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Limits every query of the model to the logged-in user's accessible branches (see BranchAccess).
 *
 * Because it is a global scope it also covers show, update and every action that loads a
 * record by id, not only lists: a record of another branch simply "does not exist" (404) for
 * that user, so ids from other branches cannot be guessed or used.
 *
 * Use Model::withoutGlobalScope('branch') only in system code that has already authorised the
 * access (for example cross-branch repayments or nightly jobs), never with arbitrary user input.
 */
trait BelongsToBranch
{
    protected static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $query) {
            $branchIds = BranchAccess::restrictedBranchIds();

            if ($branchIds !== null) {
                $query->whereIn($query->getModel()->getTable().'.branch_id', $branchIds);
            }
        });
    }
}
