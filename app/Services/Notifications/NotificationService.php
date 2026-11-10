<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\Language;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use App\Jobs\SendSmsNotification;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\NotificationLog;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\SystemAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * The one notification pipeline of the system (design section 10). Modules never send SMS
 * themselves; they call this service (through the domain event listeners).
 *
 *   event → template (product → category → global, customer's language) → render with the
 *   event's placeholders only → skip if the same text went to the same number within
 *   notifications.dedupe_minutes → log → queue the SMS (sent after the database commit)
 *
 * Staff get in-app notifications through the starter's database notifications.
 * OTP codes do not go through here: OtpService sends them and they are never logged.
 */
class NotificationService
{
    public function __construct(
        protected TemplateService $templates,
    ) {}

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function notifyCustomer(NotificationEvent $event, Customer $customer, array $context, ?Loan $loan = null): NotificationLog
    {
        $destination = $customer->phone_primary;
        $template = $this->templates->resolve($event->value, NotificationChannel::Sms, $customer->preferred_language ?? Language::English, $loan?->product);

        $log = new NotificationLog([
            'notifiable_type' => $customer->getMorphClass(),
            'notifiable_id' => $customer->id,
            'loan_id' => $loan?->id,
            'event' => $event->value,
            'channel' => NotificationChannel::Sms,
            'destination' => $destination,
            'template_id' => $template?->id,
        ]);

        if (! $template) {
            return $this->skip($log, 'No active template for this message');
        }

        $text = $this->templates->render($template->body, $event->placeholders(), $context + [
            'company_name' => Setting::value('company.name', 'CDP Capital'),
            'customer_name' => $customer->name_with_initials,
        ]);

        $log->message_hash = hash('sha256', $destination.'|'.$text);

        if ($this->isDuplicate($destination, $log->message_hash)) {
            return $this->skip($log, 'Same message sent to this number a moment ago');
        }

        $log->status = NotificationStatus::Queued;
        $log->save();

        // The text travels in the job only; the log keeps just its hash.
        SendSmsNotification::dispatch($log->id, $text)->afterCommit();

        return $log;
    }

    /**
     * In-app notification to staff (the starter's notifications table and endpoints).
     *
     * @param  Collection<int, User>|User  $users
     * @param  array<string, mixed>  $data
     */
    public function notifyStaff(Collection|User $users, string $type, string $title, string $message, array $data = []): void
    {
        $users = $users instanceof User ? collect([$users]) : $users;

        if ($users->isNotEmpty()) {
            Notification::send($users, new SystemAlert($type, $title, $message, $data));
        }
    }

    /**
     * Active staff of a branch (or head office) holding a permission.
     *
     * @return Collection<int, User>
     */
    public function staffWithPermission(string $permission, ?int $branchId): Collection
    {
        // Spatie throws for an unknown permission; nobody holding it simply means nobody to alert,
        // and must never stop the nightly run.
        if (! Permission::where('name', $permission)->where('guard_name', 'api')->exists()) {
            return collect();
        }

        return User::permission($permission)
            ->where('is_active', true)
            ->whereIn('user_type', ['admin', 'staff'])
            ->with('employee')
            ->get()
            ->filter(fn (User $u) => $u->user_type === 'admin' || $u->can('Branch Data All') || $u->employee?->branch_id === $branchId)
            ->values();
    }

    protected function isDuplicate(string $destination, string $hash): bool
    {
        $minutes = (int) Setting::value('notifications.dedupe_minutes', 5);

        return $minutes > 0 && NotificationLog::query()
            ->where('destination', $destination)
            ->where('message_hash', $hash)
            ->whereIn('status', [NotificationStatus::Queued->value, NotificationStatus::Sent->value])
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->exists();
    }

    protected function skip(NotificationLog $log, string $reason): NotificationLog
    {
        $log->forceFill([
            'message_hash' => $log->message_hash ?? str_repeat('0', 64),
            'status' => NotificationStatus::Skipped,
            'error' => $reason,
        ])->save();

        return $log;
    }
}
