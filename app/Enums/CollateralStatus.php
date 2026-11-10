<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * proposed  → recorded with its valuation
 * verified  → checked by someone other than the person who recorded it
 * perfected → the security is legally in place (mortgage bond registered, CR endorsed,
 *             gold sealed in the vault); needed before disbursement
 * released  → handed back (loan settled, rejected or cancelled)
 * realised  → sold to recover a defaulted loan
 */
enum CollateralStatus: string
{
    case Proposed = 'proposed';
    case Verified = 'verified';
    case Perfected = 'perfected';
    case Released = 'released';
    case Realised = 'realised';
}
