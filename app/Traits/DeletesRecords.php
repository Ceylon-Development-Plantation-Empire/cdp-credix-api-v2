<?php

declare(strict_types=1);

namespace App\Traits;

use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * DELETE for records nothing else depends on. A record that is still in use is refused with 409
 * (the caller's $blocker names the reason; a foreign key is the last line of defence), so a
 * delete never leaves loans, ledger entries or documents pointing at nothing.
 */
trait DeletesRecords
{
    /**
     * @param  (callable(Model): ?string)|null  $blocker  Returns why the record cannot be deleted, or null.
     */
    protected function destroyRecord(?Model $model, string $label, string $module, ?callable $blocker = null): JsonResponse
    {
        if (! $model) {
            return $this->notFound(ucfirst($label).' not found');
        }

        if ($blocker && ($reason = $blocker($model))) {
            throw new BusinessRuleException('RECORD_IN_USE', $reason, 409);
        }

        try {
            $model->delete();
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            throw new BusinessRuleException('RECORD_IN_USE', "This {$label} is in use and cannot be deleted. Deactivate it instead.", 409);
        }

        $this->logActivity('DELETE', $module, "Deleted {$label} #{$model->getKey()}");

        return response()->json([
            'status' => 'success',
            'message' => ucfirst($label).' deleted successfully',
        ], 200);
    }
}
