<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    // Not sent: the same message went to the same number a moment ago, or there is no template.
    case Skipped = 'skipped';
}
