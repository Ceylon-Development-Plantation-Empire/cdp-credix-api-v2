<?php

declare(strict_types=1);

namespace App\Enums;

enum OtpStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';

    // Too many wrong codes, or replaced by a newer challenge.
    case Locked = 'locked';
    case Superseded = 'superseded';
}
