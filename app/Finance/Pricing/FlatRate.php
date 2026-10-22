<?php

declare(strict_types=1);

namespace App\Finance\Pricing;

use App\Finance\Schedule\LoanTerms;
use App\Finance\Schedule\Schedule;
use App\Finance\Schedule\ScheduleLine;
use App\Finance\Support\Money;

/**
 * Flat rate: interest is worked out once on the original principal for the whole term.
 *
 *   interest    = principal × annual rate × term in years
 *   installment = (principal + interest) / installments
 *
 * The rate is per annum, so a 24-month loan costs twice a 12-month loan at the same rate.
 * (The old system charged rate × amount once regardless of the term.)
 *
 * Example: 100,000 at 24% p.a. over 12 months → interest 24,000, total 124,000,
 * 11 installments of 10,333.33 and a last one of 10,333.37.
 */
class FlatRate extends AbstractPricing
{
    public function buildSchedule(LoanTerms $terms): Schedule
    {
        $this->assertInstallmentFrequency($terms);
        $this->assertNoResidualValue($terms);

        $totalInterest = Money::round($terms->principal->multipliedBy($terms->rateFraction())->multipliedBy($terms->termInYears()));

        $principalParts = $this->equalSplit($terms->principal, $terms->tenure);
        $interestParts = $this->equalSplit($totalInterest, $terms->tenure);
        $dueDates = $this->dueDates->generate($terms);

        $lines = [];
        $balance = $terms->principal;

        foreach ($dueDates as $index => $dueDate) {
            $closing = $balance->minus($principalParts[$index]);

            $lines[] = new ScheduleLine($index + 1, $dueDate, $balance, $principalParts[$index], $interestParts[$index], $closing);

            $balance = $closing;
        }

        return new Schedule($lines, Money::zero());
    }
}
