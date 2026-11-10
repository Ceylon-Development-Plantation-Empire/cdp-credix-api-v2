<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Drives which employment documents are mandatory (design section 7.4).
 */
enum EmploymentStatus: string
{
    case Employed = 'employed';
    case SelfEmployed = 'self_employed';
    case Unemployed = 'unemployed';
    case Retired = 'retired';
}
