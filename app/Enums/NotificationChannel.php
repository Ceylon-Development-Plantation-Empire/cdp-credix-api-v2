<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Sms = 'sms';
    case Email = 'email';

    // Agreement and letter texts (rendered into documents, never sent).
    case Document = 'document';
}
