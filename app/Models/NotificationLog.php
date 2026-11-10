<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An outbound message (SMS / email) to a customer: the outbound rows of the notifications table.
 * In-app staff alerts (channel "database") live in the same table but are Laravel's own
 * notifications and never appear here.
 */
class NotificationLog extends Model
{
    use HasUuids;

    protected $table = 'notifications';

    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'loan_id',
        'event',
        'channel',
        'destination',
        'template_id',
        'message_hash',
        'status',
        'provider_reference',
        'error',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'message_hash',
        'data',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('outbound', fn (Builder $query) => $query->where('notifications.channel', '!=', 'database'));

        // Laravel's columns: the type names the message, data stays empty (the text is never kept).
        static::creating(function (NotificationLog $log) {
            $log->type ??= "outbound.{$log->event}";
            $log->data ??= '{}';
        });
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
