<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Sri Lankan NIC numbers come in two formats for the same person:
 *
 *   old (before 2016): 9 digits + V/X   e.g. 853400937V   → YY DDD SSS C
 *   new:               12 digits         e.g. 198534000937 → YYYY DDD 0SSS C
 *
 * We always index the 12-digit form (the blind index customers.id_number_hash is computed from
 * it) so a customer who registered with the old card and comes back with the new one is
 * recognised as the same person. The old system compared "NIC variants" in queries; here the
 * database unique index does it.
 *
 * DDD is the day of the year of birth; 500 is added for women. The NIC day count always
 * treats February as having 29 days.
 */
final class NicNormalizer
{
    /**
     * Returns the 12-digit NIC, or null when the value is not a valid NIC.
     */
    public static function normalize(?string $nic): ?string
    {
        if ($nic === null) {
            return null;
        }

        $nic = strtoupper(preg_replace('/\s+/', '', $nic));

        if (preg_match('/^(\d{2})(\d{3})(\d{3})(\d)[VX]$/', $nic, $parts)) {
            $normalized = '19'.$parts[1].$parts[2].'0'.$parts[3].$parts[4];
        } elseif (preg_match('/^\d{12}$/', $nic)) {
            $normalized = $nic;
        } else {
            return null;
        }

        return self::dayOfYear($normalized) === null ? null : $normalized;
    }

    public static function isValid(?string $nic): bool
    {
        return self::normalize($nic) !== null;
    }

    /**
     * Date of birth encoded in the NIC. Used to cross-check the date entered on the form.
     */
    public static function birthDate(string $nic): ?CarbonImmutable
    {
        $normalized = self::normalize($nic);

        if ($normalized === null) {
            return null;
        }

        $year = (int) substr($normalized, 0, 4);
        $day = self::dayOfYear($normalized);

        // Map the day number onto a leap-year calendar (2000), then move it to the real year.
        $date = CarbonImmutable::create(2000, 1, 1)->addDays($day - 1);

        return checkdate($date->month, $date->day, $year)
            ? CarbonImmutable::create($year, $date->month, $date->day)
            : null;
    }

    public static function gender(string $nic): ?string
    {
        $normalized = self::normalize($nic);

        if ($normalized === null) {
            return null;
        }

        return (int) substr($normalized, 4, 3) > 500 ? 'female' : 'male';
    }

    /**
     * The day of year without the +500 for women, or null when out of range.
     */
    private static function dayOfYear(string $normalized): ?int
    {
        $day = (int) substr($normalized, 4, 3);

        if ($day > 500) {
            $day -= 500;
        }

        return $day >= 1 && $day <= 366 ? $day : null;
    }
}
