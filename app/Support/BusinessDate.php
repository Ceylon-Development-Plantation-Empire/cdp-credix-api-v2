<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * The business calendar. One time zone everywhere: Sri Lanka (config app.business_timezone, from
 * APP_TIMEZONE). The application clock, the database session and the API all use it, so "now",
 * "today" and every stored moment agree. A business DATE (due date, value date, the nightly run's
 * date) is midnight of that calendar day in the same zone, exactly the shape Eloquent gives DATE
 * columns, so a comparison with a due_date / value_date / grace_ends_on column compares calendar days.
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
        return Carbon::now(self::timezone())->startOfDay();
    }

    /**
     * Today's business date (immutable, like CarbonImmutable::today()).
     */
    public static function todayImmutable(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /**
     * The business date a moment falls on (a UTC instant -> its Sri Lankan calendar date).
     */
    public static function of(DateTimeInterface|string $moment): CarbonImmutable
    {
        $instant = $moment instanceof DateTimeInterface ? CarbonImmutable::instance($moment) : CarbonImmutable::parse($moment, self::timezone());

        return $instant->setTimezone(self::timezone())->startOfDay();
    }

    /**
     * A moment as shown by the API: ISO 8601 in the business timezone (+05:30).
     */
    public static function iso(?CarbonInterface $moment): ?string
    {
        return $moment?->copy()->setTimezone(self::timezone())->toIso8601String();
    }
}
