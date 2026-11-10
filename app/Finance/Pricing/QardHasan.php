<?php

declare(strict_types=1);

namespace App\Finance\Pricing;

use App\Enums\RepaymentFrequency;
use App\Finance\Schedule\LoanTerms;
use App\Finance\Schedule\Schedule;
use App\Finance\Schedule\ScheduleLine;
use App\Finance\Support\Money;
use InvalidArgumentException;

/**
 * Qard Hasan (benevolent loan): the customer repays exactly what was lent.
 *
 * Any profit on a loan is riba, so the rate must be zero. The only cost Shariah allows is
 * a fixed service fee equal to the actual cost of providing the loan; that is a product
 * charge (validated as fixed, never a % of the amount), not part of this schedule.
 */
class QardHasan extends AbstractPricing
{
    public function buildSchedule(LoanTerms $terms): Schedule
    {
        if (! $terms->annualRate->isZero()) {
            throw new InvalidArgumentException('Qard Hasan cannot carry a profit rate.');
        }

        $this->assertNoResidualValue($terms);

        $dueDates = $this->dueDates->generate($terms);

        // A Qard can be repaid in one go at maturity or in equal parts.
        $parts = $terms->frequency === RepaymentFrequency::Bullet
            ? [$terms->principal]
            : $this->equalSplit($terms->principal, $terms->tenure);

        $lines = [];
        $balance = $terms->principal;

        foreach ($parts as $index => $principal) {
            $closing = $balance->minus($principal);
            $lines[] = new ScheduleLine($index + 1, $dueDates[$index], $balance, $principal, Money::zero(), $closing);
            $balance = $closing;
        }

        return new Schedule($lines, Money::zero());
    }
}
