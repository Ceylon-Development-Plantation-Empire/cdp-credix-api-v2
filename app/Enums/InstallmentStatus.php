<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * upcoming → due (due date reached) → overdue (grace period over, still unpaid);
 * partially_paid / paid as payments come in; rescheduled when a restructure replaces it.
 */
enum InstallmentStatus: string
{
    case Upcoming = 'upcoming';
    case Due = 'due';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Rescheduled = 'rescheduled';
}
