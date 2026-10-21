<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Generic in-app notification for staff (recovery case to assign, case assigned …), stored in the
 * starter's notifications table and read through its /notifications endpoints.
 */
class SystemAlert extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data  Safe references only (public ids, numbers), never personal data.
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $message,
        public array $data = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
        ] + $this->data;
    }
}
