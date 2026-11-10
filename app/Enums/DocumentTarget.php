<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a document type is attached to.
 */
enum DocumentTarget: string
{
    // A customer: borrower, co-borrower, group member or guarantor.
    case Person = 'person';
    case Collateral = 'collateral';
    case Loan = 'loan';
}
