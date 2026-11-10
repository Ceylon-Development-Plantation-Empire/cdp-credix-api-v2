<?php

declare(strict_types=1);

namespace App\Enums;

enum BorrowerGroupStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Dissolved = 'dissolved';
}
