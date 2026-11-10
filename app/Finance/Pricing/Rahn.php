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
 * Rahn (Islamic gold): a Qard Hasan secured by pledged gold.
 *
 * The loan itself carries no profit; the customer repays the advance at maturity.
 * CDP earns a safekeeping fee (ujrah) for storing the gold, and that fee depends on the
 * weight of the gold and the time it is kept, never on the loan amount (otherwise it
 * would be interest under another name). The fee is a product charge of type
 * safekeeping_fee calculated per gram per month.
 */
class Rahn extends AbstractPricing
{
    public function buildSchedule(LoanTerms $terms): Schedule
    {
        if (! $terms->annualRate->isZero()) {
            throw new InvalidArgumentException('Rahn cannot carry a profit rate; use a safekeeping fee by weight.');
        }

        if ($terms->frequency !== RepaymentFrequency::Bullet) {
            throw new InvalidArgumentException('Rahn is repaid in one payment at maturity (bullet).');
        }

        $this->assertNoResidualValue($terms);

        $maturity = $this->dueDates->generate($terms)[0];

        return new Schedule([
            new ScheduleLine(1, $maturity, $terms->principal, $terms->principal, Money::zero(), Money::zero()),
        ], Money::zero());
    }
}
