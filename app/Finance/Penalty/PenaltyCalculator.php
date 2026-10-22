<?php

declare(strict_types=1);

namespace App\Finance\Penalty;

use App\Enums\ChargeCalculation;
use App\Enums\ChargeRecurrence;
use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Late payment charges (decision D-4).
 *
 * Both ways of charging are supported and the product (or the system setting) picks one:
 *   - fixed:               a flat rupee amount per charge
 *   - percent_of_overdue:  a % of the unpaid installment amount
 *
 * The overdue amount passed in must exclude earlier penalties, so a penalty is never
 * charged on a penalty. For Islamic products this matters twice: compounding late charges
 * are not allowed, and the charge is credited to charity rather than income (the
 * is_charity flag on the product charge decides where it is posted).
 */
class PenaltyCalculator
{
    public function amount(
        ChargeCalculation $calculation,
        BigDecimal|string $value,
        BigDecimal|string $overdueAmount,
        BigDecimal|string|null $minAmount = null,
        BigDecimal|string|null $maxAmount = null,
    ): BigDecimal {
        $overdue = Money::of($overdueAmount);

        if (! $overdue->isGreaterThan(0)) {
            return Money::zero();
        }

        $penalty = match ($calculation) {
            ChargeCalculation::Fixed => Money::of($value),
            ChargeCalculation::PercentOfOverdue => Money::percentOf($overdue, $value),
            default => throw new InvalidArgumentException("{$calculation->value} cannot be used for a late payment charge."),
        };

        if ($minAmount !== null) {
            $penalty = Money::max($penalty, Money::of($minAmount));
        }

        if ($maxAmount !== null) {
            $penalty = Money::min($penalty, Money::of($maxAmount));
        }

        return $penalty;
    }

    /**
     * The dates on which a penalty is due for one installment, up to and including $asOf,
     * that have not been charged yet.
     *
     * The first charge falls on the day after the grace period ends. With monthly recurrence
     * the next charges fall on the same day of each following month (counted from that first
     * date, so the day never drifts). Passing the date of the last charge makes this safe to
     * run every night: an already charged date is never returned again.
     *
     * Example: due 10 Jan, grace 7 days → first charge 18 Jan, then 18 Feb, 18 Mar …
     *
     * @return list<CarbonImmutable>
     */
    public function chargeDates(
        CarbonImmutable $dueDate,
        int $graceDays,
        ChargeRecurrence $recurrence,
        ?CarbonImmutable $lastChargedOn,
        CarbonImmutable $asOf,
    ): array {
        $first = $dueDate->startOfDay()->addDays($graceDays + 1);
        $asOf = $asOf->startOfDay();

        if ($asOf->lessThan($first)) {
            return [];
        }

        if ($recurrence === ChargeRecurrence::Once) {
            return $lastChargedOn === null ? [$first] : [];
        }

        if ($recurrence !== ChargeRecurrence::MonthlyWhileOverdue) {
            throw new InvalidArgumentException("{$recurrence->value} is not a late payment recurrence.");
        }

        $dates = [];

        for ($month = 0; ($date = $first->addMonthsNoOverflow($month))->lessThanOrEqualTo($asOf); $month++) {
            if ($lastChargedOn === null || $date->greaterThan($lastChargedOn->startOfDay())) {
                $dates[] = $date;
            }
        }

        return $dates;
    }
}
