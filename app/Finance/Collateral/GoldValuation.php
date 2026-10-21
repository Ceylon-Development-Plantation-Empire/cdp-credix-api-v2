<?php

namespace App\Finance\Collateral;

use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Values pledged gold and the advance it can secure.
 *
 * Gold is priced on its pure content: 22 karat jewellery is 22/24 pure, so 10 g of
 * 22K contains 9.167 g of pure gold. The market rate is entered per gram of 24K.
 *
 *   value          = net weight (g) × karat / 24 × 24K rate per gram
 *   max advance    = value × LTV %
 *   safekeeping    = net weight (g) × fee per gram per month × months      (Rahn)
 *
 * Net weight is the gold left after stones and other metal are deducted; the valuer
 * records both gross and net weight on the collateral.
 */
class GoldValuation
{
    public function value(BigDecimal|string $netWeightGrams, int $karat, BigDecimal|string $ratePerGram24k): BigDecimal
    {
        if ($karat < 1 || $karat > 24) {
            throw new InvalidArgumentException('Karat must be between 1 and 24.');
        }

        $weight = BigDecimal::of($netWeightGrams);

        if (! $weight->isGreaterThan(0)) {
            throw new InvalidArgumentException('Net weight must be greater than zero.');
        }

        return Money::round(
            $weight->multipliedBy($karat)->multipliedBy($ratePerGram24k)->dividedBy(24, Money::PRECISION, RoundingMode::HALF_UP)
        );
    }

    /**
     * Maximum amount that can be lent against a collateral value (any collateral type).
     */
    public function eligibleAmount(BigDecimal|string $value, BigDecimal|string $ltvPercent): BigDecimal
    {
        $ltv = BigDecimal::of($ltvPercent);

        if ($ltv->isNegative() || $ltv->isGreaterThan(100)) {
            throw new InvalidArgumentException('LTV must be between 0 and 100 percent.');
        }

        return Money::percentOf($value, $ltv);
    }

    public function safekeepingFee(BigDecimal|string $netWeightGrams, BigDecimal|string $feePerGramPerMonth, int $months): BigDecimal
    {
        return Money::round(BigDecimal::of($netWeightGrams)->multipliedBy($feePerGramPerMonth)->multipliedBy(max(0, $months)));
    }
}
