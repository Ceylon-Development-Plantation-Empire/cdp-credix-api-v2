<?php

declare(strict_types=1);

namespace App\Finance\Schedule;

use App\Enums\RepaymentFrequency;
use Carbon\CarbonImmutable;

class DueDateGenerator
{
    /**
     * Build the due date for every installment.
     *
     * Monthly dates are always counted from the first due date (first + 1 month,
     * first + 2 months …), not from the previous due date. Counting from the previous
     * date makes the day drift: 31 Jan → 28 Feb → 28 Mar. Counting from the anchor
     * keeps 31 Jan → 28 Feb → 31 Mar.
     *
     * addMonthsNoOverflow stops 31 Jan + 1 month from becoming 3 March.
     *
     * @return list<CarbonImmutable>
     */
    public function generate(LoanTerms $terms): array
    {
        $first = $terms->firstDueDate ?? $this->defaultFirstDueDate($terms);
        $dates = [];

        for ($i = 0; $i < $terms->tenure; $i++) {
            $dates[] = match ($terms->frequency) {
                RepaymentFrequency::Monthly => $first->addMonthsNoOverflow($i),
                RepaymentFrequency::Fortnightly => $first->addDays(14 * $i),
                RepaymentFrequency::Weekly => $first->addDays(7 * $i),
                // A bullet loan has a single line, so this branch only runs once.
                RepaymentFrequency::Bullet => $first,
            };
        }

        return $dates;
    }

    /**
     * Without a fixed repayment day the first installment falls one period after disbursement.
     * For a bullet loan the "tenure" is the term in months, so the single due date is maturity.
     */
    protected function defaultFirstDueDate(LoanTerms $terms): CarbonImmutable
    {
        return match ($terms->frequency) {
            RepaymentFrequency::Monthly => $terms->startDate->addMonthsNoOverflow(1),
            RepaymentFrequency::Fortnightly => $terms->startDate->addDays(14),
            RepaymentFrequency::Weekly => $terms->startDate->addDays(7),
            RepaymentFrequency::Bullet => $terms->startDate->addMonthsNoOverflow($terms->tenure),
        };
    }
}
