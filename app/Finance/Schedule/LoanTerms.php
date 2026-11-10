<?php

declare(strict_types=1);

namespace App\Finance\Schedule;

use App\Enums\RepaymentFrequency;
use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Everything a pricing method needs to build a schedule.
 *
 * It is a plain value object so the same calculation can run for a quote (nothing saved),
 * an application, an approval or a restructure.
 */
final class LoanTerms
{
    public readonly BigDecimal $principal;

    public readonly BigDecimal $annualRate;

    public readonly BigDecimal $residualValue;

    /**
     * @param  BigDecimal|string  $principal  Amount financed (for Murabaha: cost price, for a lease: asset cost).
     * @param  BigDecimal|string  $annualRate  Rate in percent per annum, e.g. "24" for 24% p.a.
     * @param  int  $tenure  Number of installments (for bullet loans: term in months).
     * @param  CarbonImmutable  $startDate  Disbursement date. Due dates count from here, not from approval.
     * @param  CarbonImmutable|null  $firstDueDate  Optional fixed first due date (e.g. the 5th of next month).
     * @param  BigDecimal|string|null  $residualValue  Balloon left at the end (leases only).
     */
    public function __construct(
        BigDecimal|string $principal,
        BigDecimal|string $annualRate,
        public readonly int $tenure,
        public readonly RepaymentFrequency $frequency,
        public readonly CarbonImmutable $startDate,
        public readonly ?CarbonImmutable $firstDueDate = null,
        BigDecimal|string|null $residualValue = null,
    ) {
        $this->principal = Money::of($principal);
        $this->annualRate = BigDecimal::of($annualRate);
        $this->residualValue = Money::of($residualValue ?? '0');

        if (! $this->principal->isGreaterThan(0)) {
            throw new InvalidArgumentException('Principal must be greater than zero.');
        }

        if ($this->annualRate->isNegative()) {
            throw new InvalidArgumentException('Rate cannot be negative.');
        }

        if ($this->tenure < 1) {
            throw new InvalidArgumentException('Tenure must be at least one installment.');
        }

        if ($this->residualValue->isNegative() || $this->residualValue->isGreaterThanOrEqualTo($this->principal)) {
            throw new InvalidArgumentException('Residual value must be zero or less than the principal.');
        }

        if ($this->firstDueDate && $this->firstDueDate->lessThanOrEqualTo($this->startDate)) {
            throw new InvalidArgumentException('First due date must be after the start date.');
        }
    }

    /**
     * The annual rate as a fraction (24% → 0.24).
     */
    public function rateFraction(): BigDecimal
    {
        return Money::divide($this->annualRate, 100);
    }

    /**
     * Rate for one repayment period (24% p.a. monthly → 0.02).
     */
    public function periodicRate(): BigDecimal
    {
        $periods = $this->frequency->periodsPerYear();

        if ($periods === null) {
            throw new InvalidArgumentException('A bullet loan has no periodic rate.');
        }

        return Money::divide($this->rateFraction(), $periods);
    }

    /**
     * Term length in years (12 monthly installments → 1, 26 weekly → 0.5).
     */
    public function termInYears(): BigDecimal
    {
        $periods = $this->frequency->periodsPerYear() ?? 12;

        return Money::divide($this->tenure, $periods);
    }
}
