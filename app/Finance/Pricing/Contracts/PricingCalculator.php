<?php

namespace App\Finance\Pricing\Contracts;

use App\Finance\Schedule\LoanTerms;
use App\Finance\Schedule\Schedule;

/**
 * Every pricing method (flat, reducing balance, murabaha …) implements this.
 *
 * Calculators are pure: no database, no settings, no clock. Give them the same terms
 * and they always return the same schedule, which is what makes them testable and
 * safe to use for quotes and for the real contract alike.
 */
interface PricingCalculator
{
    public function buildSchedule(LoanTerms $terms): Schedule;
}
