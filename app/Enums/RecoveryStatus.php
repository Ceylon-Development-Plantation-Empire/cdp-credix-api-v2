<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * open     → being worked
 * resolved → arrears cleared while the loan runs on
 * closed   → the loan itself closed (settled or written off)
 */
enum RecoveryStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
