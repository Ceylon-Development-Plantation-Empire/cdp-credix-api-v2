<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Stores every Sri Lankan phone number in one format (E.164: +94 followed by 9 digits),
 * so duplicate checks, SMS sending and OTP matching never fail because one person typed
 * 0771234567 and another typed +94 77 123 4567.
 */
final class PhoneNormalizer
{
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        $local = match (true) {
            str_starts_with($digits, '0094') => substr($digits, 4),
            str_starts_with($digits, '94') && strlen($digits) === 11 => substr($digits, 2),
            str_starts_with($digits, '0') && strlen($digits) === 10 => substr($digits, 1),
            strlen($digits) === 9 => $digits,
            default => null,
        };

        return $local !== null && preg_match('/^[1-9]\d{8}$/', $local) ? '+94'.$local : null;
    }

    public static function isMobile(?string $phone): bool
    {
        $normalized = self::normalize($phone);

        // Sri Lankan mobile numbers start with 7 after the country code.
        return $normalized !== null && str_starts_with($normalized, '+947');
    }

    /**
     * +94771234567 → +9477****567, for responses and logs.
     */
    public static function mask(?string $phone): ?string
    {
        if ($phone === null || strlen($phone) < 8) {
            return $phone;
        }

        return substr($phone, 0, 5).str_repeat('*', strlen($phone) - 8).substr($phone, -3);
    }
}
