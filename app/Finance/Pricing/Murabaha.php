<?php

namespace App\Finance\Pricing;

use App\Finance\Schedule\LoanTerms;
use App\Finance\Schedule\Schedule;
use App\Finance\Schedule\ScheduleLine;
use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Murabaha (cost-plus sale).
 *
 * CDP buys the asset and sells it to the customer at an agreed price paid in installments:
 *
 *   profit      = cost price × profit rate × term in years      (fixed once, at the contract)
 *   sale price  = cost price + profit
 *   installment = sale price / installments
 *
 * The sale price is a debt and can never grow afterwards: a late payment does not add profit
 * (late charges go to charity, see the penalty calculator).
 *
 * Splitting each installment into profit and principal is for accounting only (how much profit
 * CDP has earned so far, and the unearned profit for an early-settlement rebate). We use the
 * effective rate method: find the periodic rate j at which the installments repay exactly the
 * cost price, then profit of a line = opening cost balance × j. Earlier lines earn more profit
 * because more cost is outstanding, which is how IFRS amortised cost recognises income.
 */
class Murabaha extends AbstractPricing
{
    public function buildSchedule(LoanTerms $terms): Schedule
    {
        $this->assertInstallmentFrequency($terms);
        $this->assertNoResidualValue($terms);

        $cost = $terms->principal;
        $profit = Money::round($cost->multipliedBy($terms->rateFraction())->multipliedBy($terms->termInYears()));
        $salePrice = $cost->plus($profit);

        $installments = $this->equalSplit($salePrice, $terms->tenure);
        $effectiveRate = $profit->isZero()
            ? BigDecimal::zero()
            : $this->effectiveRate($cost, $installments[0], $terms->tenure);

        $dueDates = $this->dueDates->generate($terms);
        $lines = [];
        $balance = $cost;
        $last = count($dueDates) - 1;

        foreach ($dueDates as $index => $dueDate) {
            if ($index === $last) {
                // Last line: whatever cost is left is principal, the rest of the installment is profit.
                $principal = $balance;
                $lineProfit = $installments[$index]->minus($principal);
            } else {
                $lineProfit = Money::round($balance->multipliedBy($effectiveRate));
                $principal = $installments[$index]->minus($lineProfit);
            }

            $closing = $balance->minus($principal);
            $lines[] = new ScheduleLine($index + 1, $dueDate, $balance, $principal, $lineProfit, $closing);
            $balance = $closing;
        }

        return new Schedule($lines, Money::zero());
    }

    /**
     * Find the periodic rate j where the present value of n installments equals the cost:
     *
     *   installment × (1 − (1+j)^−n) / j = cost
     *
     * There is no closed formula for j, so we narrow it down by bisection. The present value
     * falls as j rises, so if PV is too high j must go up. 60 rounds take the interval below
     * 1e-18, far finer than the cents we round to.
     */
    protected function effectiveRate(BigDecimal $cost, BigDecimal $installment, int $periods): BigDecimal
    {
        $low = BigDecimal::zero();
        $high = BigDecimal::one();

        for ($round = 0; $round < 60; $round++) {
            $mid = $low->plus($high)->dividedBy(2, 20, RoundingMode::HALF_UP);

            if ($this->presentValue($installment, $mid, $periods)->isGreaterThan($cost)) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return $low->plus($high)->dividedBy(2, 20, RoundingMode::HALF_UP);
    }

    protected function presentValue(BigDecimal $installment, BigDecimal $rate, int $periods): BigDecimal
    {
        if ($rate->isZero()) {
            return $installment->multipliedBy($periods);
        }

        $discount = BigDecimal::one()->minus(BigDecimal::one()->dividedBy($this->growthFactor($rate, $periods), 20, RoundingMode::HALF_UP));

        return $installment->multipliedBy($discount)->dividedBy($rate, 20, RoundingMode::HALF_UP);
    }
}
