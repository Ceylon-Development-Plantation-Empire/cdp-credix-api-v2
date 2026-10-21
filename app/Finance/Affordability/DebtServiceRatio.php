<?php

namespace App\Finance\Affordability;

use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Debt service ratio: how much of the customer's monthly income goes to loan repayments
 * once the new installment is added.
 *
 *   DSR % = (existing monthly obligations + new installment) / net monthly income × 100
 *
 * An application is refused when it is above the product's max_dsr_percent
 * (or the affordability.max_dsr_percent setting).
 */
class DebtServiceRatio
{
    public function percent(BigDecimal|string $netMonthlyIncome, BigDecimal|string $existingObligations, BigDecimal|string $newInstallment): BigDecimal
    {
        $income = Money::of($netMonthlyIncome);

        if (! $income->isGreaterThan(0)) {
            throw new InvalidArgumentException('Net monthly income must be greater than zero to assess affordability.');
        }

        $obligations = Money::of($existingObligations)->plus(Money::of($newInstallment));

        return $obligations->multipliedBy(100)->dividedBy($income, 2, RoundingMode::HALF_UP);
    }

    public function isAffordable(BigDecimal $dsrPercent, BigDecimal|string $maxPercent): bool
    {
        return $dsrPercent->isLessThanOrEqualTo($maxPercent);
    }
}
