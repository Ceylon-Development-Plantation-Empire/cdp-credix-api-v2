<?php

namespace App\Support;

/**
 * Masking for personal data in API responses.
 *
 * Users without the "Customer View Sensitive" permission see enough to recognise a customer
 * (last digits) but not enough to misuse the data.
 */
final class Mask
{
    public const SENSITIVE_PERMISSION = 'Customer View Sensitive';

    /**
     * 198534000937 → 1985******37
     */
    public static function idNumber(?string $value): ?string
    {
        if ($value === null || strlen($value) <= 6) {
            return $value;
        }

        return substr($value, 0, 4).str_repeat('*', strlen($value) - 6).substr($value, -2);
    }

    public static function email(?string $value): ?string
    {
        if ($value === null || ! str_contains($value, '@')) {
            return $value;
        }

        [$local, $domain] = explode('@', $value, 2);

        return substr($local, 0, 2).str_repeat('*', max(1, strlen($local) - 2)).'@'.$domain;
    }

    public static function canSeeSensitive(): bool
    {
        return (bool) auth('api')->user()?->can(self::SENSITIVE_PERMISSION);
    }
}
