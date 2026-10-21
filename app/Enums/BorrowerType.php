<?php

namespace App\Enums;

/**
 * Who borrows: one person, several people jointly (borrower + co-borrowers, all liable),
 * or a borrower group (one facility, one loan per member).
 */
enum BorrowerType: string
{
    case Individual = 'individual';
    case Joint = 'joint';
    case Group = 'group';
}
