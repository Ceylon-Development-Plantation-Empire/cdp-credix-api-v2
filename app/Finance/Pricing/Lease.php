<?php

namespace App\Finance\Pricing;

use App\Finance\Schedule\LoanTerms;
use App\Finance\Schedule\Schedule;

/**
 * Finance lease (vehicle lease).
 *
 * Priced as an annuity on the asset cost that leaves an agreed residual (balloon) value
 * at the end. The customer pays the residual to take ownership.
 *
 *   rental = (C − RV / (1+i)^n) × i / (1 − (1+i)^−n)
 *
 * With no residual a lease prices exactly like a reducing balance loan.
 */
class Lease extends ReducingBalance
{
    public function buildSchedule(LoanTerms $terms): Schedule
    {
        $this->assertInstallmentFrequency($terms);

        return $this->amortise($terms, $terms->principal, $terms->residualValue);
    }
}
