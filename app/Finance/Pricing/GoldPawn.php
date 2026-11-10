<?php

declare(strict_types=1);

namespace App\Finance\Pricing;

use App\Enums\RepaymentFrequency;
use App\Finance\Schedule\LoanTerms;
use App\Finance\Schedule\Schedule;
use App\Finance\Schedule\ScheduleLine;
use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Gold pawning (conventional).
 *
 * One payment at maturity: the advance plus interest for the actual number of days.
 * Pawning customers often redeem early or renew, so interest is always worked out by
 * days (actual/365) rather than by months.
 *
 *   interest = principal × annual rate × days / 365
 *
 * The advance itself is limited by the gold value (see GoldValuation).
 */
class GoldPawn extends AbstractPricing
{
    public function buildSchedule(LoanTerms $terms): Schedule
    {
        if ($terms->frequency !== RepaymentFrequency::Bullet) {
            throw new InvalidArgumentException('Gold pawning is repaid in one payment at maturity (bullet).');
        }

        $this->assertNoResidualValue($terms);

        $maturity = $this->dueDates->generate($terms)[0];
        $interest = self::accruedInterest($terms->principal, $terms->annualRate, $terms->startDate, $maturity);

        return new Schedule([
            new ScheduleLine(1, $maturity, $terms->principal, $terms->principal, $interest, Money::zero()),
        ], Money::zero());
    }

    /**
     * Interest earned between two dates. Used for redemption before maturity, renewals,
     * and early settlement of any loan that accrues by days.
     */
    public static function accruedInterest(BigDecimal $principal, BigDecimal|string $annualRate, CarbonImmutable $from, CarbonImmutable $to): BigDecimal
    {
        $days = max(0, (int) $from->startOfDay()->diffInDays($to->startOfDay()));

        return Money::round(
            $principal->multipliedBy(Money::divide($annualRate, 100))->multipliedBy($days)->dividedBy(365, Money::PRECISION, RoundingMode::HALF_UP)
        );
    }
}
