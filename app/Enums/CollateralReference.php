<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What proves a collateral is perfected (legally in place) before disbursement.
 */
enum CollateralReference: string
{
    // Mortgage bond number, CR endorsement, lien reference …  (collaterals.registration_ref)
    case Registration = 'registration';
    // Packet number and vault location of goods held by CDP, such as gold (collaterals.custody_ref)
    case Custody = 'custody';

    /**
     * The collaterals column that holds this reference.
     */
    public function column(): string
    {
        return match ($this) {
            self::Registration => 'registration_ref',
            self::Custody => 'custody_ref',
        };
    }

    /**
     * Shown when the reference is missing at perfection.
     */
    public function missingMessage(): string
    {
        return match ($this) {
            self::Registration => 'The registration reference (mortgage bond number / CR endorsement) is required.',
            self::Custody => 'The packet number and vault location (custody_ref) are required.',
        };
    }
}
