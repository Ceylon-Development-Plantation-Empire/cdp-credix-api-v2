<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * The business calendar: Sri Lankan dates, while the application clock and the database are UTC.
 *
 * DECISION: moments (created_at, disbursed_at …) are stored and computed in UTC; a business DATE
 * (due date, value date, the nightly run's date) is the calendar date in Sri Lanka
 * (config app.business_timezone, from APP_TIMEZONE). Business dates are returned as midnight UTC of
 * that calendar date, exactly the shape Eloquent gives DATE columns, so every comparison with a
 * due_date / value_date / grace_ends_on column compares calendar days, never instants.
 *
 * Example: at 2026-10-21 01:00 in Colombo (= 2026-10-20 19:30 UTC) the business date is
 * 2026-10-21, although UTC is still on the 20th.
 */
final class BusinessDate
{
    /**
     * The business timezone (Asia/Colombo).
     */
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'Asia/Colombo');
    }

    /**
     * Today's business date (mutable, like Laravel's today()).
     */
    public static function today(): Carbon
    {
        return Carbon::parse(Carbon::now(self::timezone())->toDateString(), 'UTC');
    }

    /**
     * Today's business date (immutable, like CarbonImmutable::today()).
     */
    public static function todayImmutable(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(self::timezone())->toDateString(), 'UTC');
    }

    /**
     * The business date a moment falls on (a UTC instant -> its Sri Lankan calendar date).
     */
    public static function of(DateTimeInterface|string $moment): CarbonImmutable
    {
        $instant = $moment instanceof DateTimeInterface ? CarbonImmutable::instance($moment) : CarbonImmutable::parse($moment, 'UTC');

        return CarbonImmutable::parse($instant->setTimezone(self::timezone())->toDateString(), 'UTC');
    }

    /**
     * A moment as shown by the API: ISO 8601 in the business timezone (+05:30).
     */
    public static function iso(?CarbonInterface $moment): ?string
    {
        return $moment?->copy()->setTimezone(self::timezone())->toIso8601String();
    }
}
