<?php

namespace App\Finance\Charges;

use App\Enums\ChargeCalculation;
use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Works out a charge from its calculation, value and the amount it is based on.
 *
 * The base depends on the calculation: the loan principal for percent_of_principal, the unpaid
 * installment for percent_of_overdue, the outstanding balance for percent_of_outstanding.
 * Gold safekeeping (per gram per month) is handled by GoldValuation because it needs a weight.
 */
class ChargeCalculator
{
    public function amount(
        ChargeCalculation $calculation,
        BigDecimal|string $value,
        BigDecimal|string $base,
        BigDecimal|string|null $minAmount = null,
        BigDecimal|string|null $maxAmount = null,
    ): BigDecimal {
        $charge = match ($calculation) {
            ChargeCalculation::Fixed => Money::of($value),
            ChargeCalculation::PercentOfPrincipal,
            ChargeCalculation::PercentOfOverdue,
            ChargeCalculation::PercentOfOutstanding => Money::percentOf(Money::of($base), $value),
            ChargeCalculation::PerGramPerMonth => throw new InvalidArgumentException('Per gram charges need the gold weight; use GoldValuation::safekeepingFee().'),
        };

        if ($minAmount !== null) {
            $charge = Money::max($charge, Money::of($minAmount));
        }

        if ($maxAmount !== null) {
            $charge = Money::min($charge, Money::of($maxAmount));
        }

        return $charge;
    }
}
