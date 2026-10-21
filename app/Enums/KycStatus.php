<?php

namespace App\Enums;

/**
 * pending  → details not yet confirmed by the customer (new, or changed since the last confirmation)
 * verified → the customer confirmed these exact details with an OTP sent to their phone
 * expired  → confirmed too long ago (setting kyc.validity_days); must be confirmed again
 */
enum KycStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Expired = 'expired';
}
