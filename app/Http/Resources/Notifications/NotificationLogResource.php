<?php

declare(strict_types=1);

namespace App\Http\Resources\Notifications;

use App\Models\NotificationLog;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The text is never stored, so it is never returned. The number is masked.
 *
 * @mixin NotificationLog
 */
class NotificationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'channel' => $this->channel->value,
            'destination' => PhoneNormalizer::mask($this->destination),
            'loan' => $this->whenLoaded('loan', fn () => $this->loan ? ['public_id' => $this->loan->public_id, 'loan_no' => $this->loan->loan_no ?? $this->loan->application_no] : null),
            'status' => $this->status->value,
            'error' => $this->error,
            'sent_at' => $this->sent_at?->toApiString(),
            'created_at' => $this->created_at?->toApiString(),
        ];
    }
}
