<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One audit-trail entry, written by the senior's ActivityLogTrait.
 *
 * SECURITY: append-only. The application can insert entries but never change or delete one, so a
 * user (or a compromised account) cannot rewrite the record of what happened. In production the
 * database user should additionally have no UPDATE / DELETE grant on activity_logs.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string $module
 * @property string $description
 * @property array<string, mixed>|null $payload
 */
class ActivityLog extends Model
{
    /**
     * Append-only: there is no updated_at column.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'module',
        'description',
        'payload',
        'level',
        'ip_address',
        'user_agent',
        'url',
        'method',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * SECURITY: refuse every change and deletion (append-only audit trail).
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit entries cannot be deleted.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Search description, module, action and IP address.
     *
     * @param  Builder<ActivityLog>  $query
     * @return Builder<ActivityLog>
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $q) use ($search) {
            $q->where('description', 'like', "%{$search}%")
                ->orWhere('module', 'like', "%{$search}%")
                ->orWhere('action', 'like', "%{$search}%")
                ->orWhere('ip_address', 'like', "%{$search}%");
        });
    }
}
