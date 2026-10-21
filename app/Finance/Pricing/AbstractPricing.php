<?php

namespace App\Finance\Pricing;

use App\Enums\RepaymentFrequency;
use App\Finance\Pricing\Contracts\PricingCalculator;
use App\Finance\Schedule\DueDateGenerator;
use App\Finance\Schedule\LoanTerms;
use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Shared helpers for the pricing methods.
 */
abstract class AbstractPricing implements PricingCalculator
{
    public function __construct(
        protected DueDateGenerator $dueDates = new DueDateGenerator,
    ) {}

    /**
     * Split a total into n equal parts rounded to cents. The last part takes whatever
     * rounding left over, so the parts always add back to the exact total.
     *
     * 100,000 / 12 → 11 × 8,333.33 and a last part of 8,333.37.
     *
     * @return list<BigDecimal>
     */
    protected function equalSplit(BigDecimal $total, int $parts): array
    {
        $regular = Money::round(Money::divide($total, $parts));
        $split = array_fill(0, $parts, $regular);
        $split[$parts - 1] = $total->minus($regular->multipliedBy($parts - 1));

        return $split;
    }

    /**
     * (1 + rate) ^ periods, rounded to a working scale after every step.
     *
     * BigDecimal::power() is exact, which means the number of decimals multiplies with
     * every period (12 decimals × 360 months). Rounding each step to 20 decimals keeps it
     * fast while staying far more precise than the 2 decimals we finally store.
     */
    protected function growthFactor(BigDecimal $rate, int $periods): BigDecimal
    {
        $base = BigDecimal::one()->plus($rate);
        $result = BigDecimal::one();

        for ($i = 0; $i < $periods; $i++) {
            $result = $result->multipliedBy($base)->toScale(20, RoundingMode::HALF_UP);
        }

        return $result;
    }

    protected function assertInstallmentFrequency(LoanTerms $terms): void
    {
        if ($terms->frequency === RepaymentFrequency::Bullet) {
            throw new InvalidArgumentException(static::class.' needs a monthly, fortnightly or weekly frequency.');
        }
    }

    protected function assertNoResidualValue(LoanTerms $terms): void
    {
        if (! $terms->residualValue->isZero()) {
            throw new InvalidArgumentException(static::class.' does not support a residual value.');
        }
    }
}
