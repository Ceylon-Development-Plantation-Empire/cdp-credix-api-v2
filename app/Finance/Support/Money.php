<?php

namespace App\Finance\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * Small helper around BigDecimal for rupee amounts.
 *
 * Why not PHP floats: 0.1 + 0.2 is 0.30000000000000004 in floating point. In a loan
 * schedule those tiny errors add up and the installments stop matching the contract.
 * BigDecimal does exact decimal arithmetic, so we only round where we decide to.
 *
 * Every amount that is stored or shown is rounded to 2 decimals with HALF_UP
 * (the usual "0.005 rounds up" rule).
 */
final class Money
{
    public const SCALE = 2;

    // Intermediate scale for rates and divisions, so rounding happens only at the end.
    public const PRECISION = 12;

    public static function of(BigNumber|int|float|string $value): BigDecimal
    {
        // Floats are turned into strings first so 0.1 stays 0.1 instead of its binary approximation.
        if (is_float($value)) {
            $value = (string) $value;
        }

        return BigDecimal::of($value)->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero()->toScale(self::SCALE);
    }

    /**
     * Round an exact (or high precision) value to rupees and cents.
     */
    public static function round(BigNumber $value): BigDecimal
    {
        return $value->toBigDecimal()->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * Divide and keep full precision for later steps.
     */
    public static function divide(BigNumber|int|string $value, BigNumber|int|string $divisor): BigDecimal
    {
        return BigDecimal::of($value)->dividedBy($divisor, self::PRECISION, RoundingMode::HALF_UP);
    }

    /**
     * value × percent / 100, rounded to cents. Used for fees, penalties and LTV.
     */
    public static function percentOf(BigNumber|int|string $value, BigNumber|int|string $percent): BigDecimal
    {
        return self::round(BigDecimal::of($value)->multipliedBy($percent)->dividedBy(100, self::PRECISION, RoundingMode::HALF_UP));
    }

    /**
     * @param  iterable<BigNumber|int|string>  $values
     */
    public static function sum(iterable $values): BigDecimal
    {
        $total = self::zero();

        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return $total;
    }

    public static function min(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->isLessThan($b) ? $a : $b;
    }

    public static function max(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->isGreaterThan($b) ? $a : $b;
    }
}
