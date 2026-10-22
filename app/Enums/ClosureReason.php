<?php

declare(strict_types=1);

namespace App\Enums;

enum ClosureReason: string
{
    case Settled = 'settled';
    case EarlySettled = 'early_settled';
    case WrittenOff = 'written_off';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    // The customer turned the offer down (or did not accept it in time).
    case Declined = 'declined';
}
