<?php

namespace App\Enums;

use App\Support\NicNormalizer;

enum IdType: string
{
    case Nic = 'nic';
    case Passport = 'passport';
    case DrivingLicence = 'driving_licence';

    /**
     * The form the number is stored and compared in, so the same document cannot be registered
     * twice with different spacing, case, or (for NICs) the old and the new format.
     * Returns null when the number is not valid for its type.
     */
    public function normalize(string $number): ?string
    {
        $clean = strtoupper(preg_replace('/[\s-]+/', '', $number));

        return match ($this) {
            self::Nic => NicNormalizer::normalize($clean),
            // Sri Lankan passports: a letter followed by 7 digits (older ones), allow 6–9 alphanumerics.
            self::Passport => preg_match('/^[A-Z][A-Z0-9]{5,8}$/', $clean) ? $clean : null,
            // Driving licence numbers: a letter and 7 digits on the new cards, or the NIC number.
            self::DrivingLicence => preg_match('/^[A-Z0-9]{6,12}$/', $clean) ? $clean : null,
        };
    }
}
