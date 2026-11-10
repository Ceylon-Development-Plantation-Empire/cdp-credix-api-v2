<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
