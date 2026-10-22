<?php

declare(strict_types=1);

namespace App\Finance\Pricing;

use App\Finance\Schedule\LoanTerms;
use App\Finance\Schedule\Schedule;
use App\Finance\Schedule\ScheduleLine;
use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Reducing balance (equal monthly installment / EMI).
 *
 * Interest is charged only on what is still owed, so every installment pays less
 * interest and more principal than the one before.
 *
 *   i   = annual rate / periods per year
 *   EMI = P × i × (1 + i)^n / ((1 + i)^n − 1)
 *
 *   each line: interest = opening balance × i, principal = EMI − interest
 *
 * The last line clears whatever balance is left, so the loan ends at exactly zero.
 *
 * Example: 100,000 at 24% p.a. over 12 months → i = 0.02, EMI 9,455.96,
 * first line interest 2,000.00 and principal 7,455.96.
 */
class ReducingBalance extends AbstractPricing
{
    public function buildSchedule(LoanTerms $terms): Schedule
    {
        $this->assertInstallmentFrequency($terms);
        $this->assertNoResidualValue($terms);

        return $this->amortise($terms, $terms->principal, Money::zero());
    }

    /**
     * Standard annuity amortisation, shared with the lease calculators.
     * $residual is the balance intentionally left at the end (zero for a normal loan).
     */
    protected function amortise(LoanTerms $terms, BigDecimal $capital, BigDecimal $residual): Schedule
    {
        $rate = $terms->periodicRate();
        $installment = $this->installment($capital, $residual, $rate, $terms->tenure);
        $dueDates = $this->dueDates->generate($terms);

        $lines = [];
        $balance = $capital;
        $last = count($dueDates) - 1;

        foreach ($dueDates as $index => $dueDate) {
            $interest = Money::round($balance->multipliedBy($rate));

            // The last line pays off exactly what is left (down to the residual), which absorbs
            // the cents lost to rounding in the earlier lines.
            $principal = $index === $last
                ? $balance->minus($residual)
                : $installment->minus($interest);

            $closing = $balance->minus($principal);

            $lines[] = new ScheduleLine($index + 1, $dueDate, $balance, $principal, $interest, $closing);

            $balance = $closing;
        }

        return new Schedule($lines, $residual);
    }

    /**
     * Regular installment for an annuity that leaves $residual at the end:
     *
     *   installment = (C − RV / (1+i)^n) × i / (1 − (1+i)^−n)
     *
     * With RV = 0 this is the familiar EMI formula. A zero rate simply splits the capital evenly.
     */
    protected function installment(BigDecimal $capital, BigDecimal $residual, BigDecimal $rate, int $periods): BigDecimal
    {
        if ($rate->isZero()) {
            return Money::round(Money::divide($capital->minus($residual), $periods));
        }

        $growth = $this->growthFactor($rate, $periods);
        $presentResidual = Money::divide($residual, $growth);
        $discount = BigDecimal::one()->minus(Money::divide(1, $growth));

        return Money::round(
            $capital->minus($presentResidual)->multipliedBy($rate)->dividedBy($discount, Money::PRECISION, RoundingMode::HALF_UP)
        );
    }
}
