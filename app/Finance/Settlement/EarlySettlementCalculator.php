<?php

namespace App\Finance\Settlement;

use App\Finance\Pricing\GoldPawn;
use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * How much it costs to close a loan before maturity.
 *
 * The two bases settle differently:
 *
 * Conventional: the customer pays the arrears, the principal not yet due, interest
 * accrued from the last due date up to today, and the early settlement fee. Future
 * interest is not charged because the money is returned early.
 *
 * Islamic (Murabaha): the remaining sale price is a fixed debt, so in principle all of it is
 * owed. CDP may give an ibra' (voluntary rebate) on the profit that has not been earned yet;
 * the rebate % is a product setting approved by the Shariah board. No accrual is added.
 */
class EarlySettlementCalculator
{
    public function conventional(
        BigDecimal|string $arrears,
        BigDecimal|string $outstandingPrincipal,
        BigDecimal|string $annualRate,
        CarbonImmutable $accrueFrom,
        CarbonImmutable $settlementDate,
        BigDecimal|string $fee = '0',
    ): SettlementQuote {
        if ($settlementDate->lessThan($accrueFrom)) {
            throw new InvalidArgumentException('Settlement date cannot be before the accrual start date.');
        }

        $principal = Money::of($outstandingPrincipal);

        return new SettlementQuote(
            arrears: Money::of($arrears),
            futureBalance: $principal,
            accruedProfit: GoldPawn::accruedInterest($principal, $annualRate, $accrueFrom, $settlementDate),
            fee: Money::of($fee),
            rebate: Money::zero(),
        );
    }

    /**
     * @param  BigDecimal|string  $remainingSalePrice  Sum of installments not yet due.
     * @param  BigDecimal|string  $unearnedProfit  Profit part of those installments.
     * @param  BigDecimal|string  $rebatePercent  % of the unearned profit given back (0 = no rebate).
     */
    public function murabaha(
        BigDecimal|string $arrears,
        BigDecimal|string $remainingSalePrice,
        BigDecimal|string $unearnedProfit,
        BigDecimal|string $rebatePercent,
    ): SettlementQuote {
        $percent = BigDecimal::of($rebatePercent);

        if ($percent->isNegative() || $percent->isGreaterThan(100)) {
            throw new InvalidArgumentException('Rebate percent must be between 0 and 100.');
        }

        $unearned = Money::of($unearnedProfit);

        if ($unearned->isGreaterThan(Money::of($remainingSalePrice))) {
            throw new InvalidArgumentException('Unearned profit cannot exceed the remaining sale price.');
        }

        return new SettlementQuote(
            arrears: Money::of($arrears),
            futureBalance: Money::of($remainingSalePrice),
            accruedProfit: Money::zero(),
            fee: Money::zero(),
            rebate: Money::percentOf($unearned, $percent),
        );
    }
}
