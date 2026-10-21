<?php

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one logged SMS through the starter's SMS gateway and records the result.
 * Retried a few times with back-off when the gateway is down.
 */
class SendSmsNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public string $logId,
        public string $text,
    ) {}

    public function handle(SmsService $sms): void
    {
        $log = NotificationLog::find($this->logId);

        if (! $log || $log->status === NotificationStatus::Sent) {
            return;
        }

        if ($sms->sendSms($log->destination, $this->text)) {
            $log->forceFill(['status' => NotificationStatus::Sent, 'sent_at' => now(), 'error' => null])->save();

            return;
        }

        $log->forceFill(['status' => NotificationStatus::Failed, 'error' => 'Gateway did not accept the message'])->save();

        // Let the queue retry; the log is set back to sent if a retry succeeds.
        $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
    }
}
