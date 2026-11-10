<?php

declare(strict_types=1);

namespace App\Enums;

enum GlJournalStatus: string
{
    // A manual journal waiting for a second person. Not part of any balance.
    case Pending = 'pending';
    case Posted = 'posted';
    case Rejected = 'rejected';
}
