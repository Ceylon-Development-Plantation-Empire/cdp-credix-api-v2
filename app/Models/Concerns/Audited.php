<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every create, update and delete of the model is written to the audit trail with its old and new
 * values (App\Support\Audit\AuditRecorder, through the senior's ActivityLogTrait).
 *
 * A model can list columns whose change alone is not worth an entry (a cache that is rebuilt on
 * every posting, a last-login stamp) in a constant AUDIT_IGNORED.
 */
trait Audited
{
    /**
     * Register the model events.
     */
    protected static function bootAudited(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::registerModelEvent($event, function (Model $model) use ($event) {
                app(AuditRecorder::class)->record($model, $event, defined(static::class.'::AUDIT_IGNORED') ? static::AUDIT_IGNORED : []);
            });
        }
    }
}
