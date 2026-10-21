<?php

namespace App\Services\Customers;

use App\Enums\InstallmentStatus;
use App\Enums\PartyRole;
use App\Models\Customer;
use App\Models\LoanInstallment;
use App\Models\LoanParty;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Internal credit score from the customer's own repayment record (design 6.7).
 *
 *   start at credit_score.base (500)
 *   + points_on_time for each installment paid by the end of its grace period
 *   − points_late    for each installment paid after its grace period
 *   − points_missed  for each installment still unpaid after its grace period
 *   kept between 0 and 1000
 *
 * Band from the share of settled installments paid on time: A ≥ 90 %, B ≥ 75 %, C ≥ 50 %, else D.
 * Only loans the person borrowed on count (not guarantees), on the schedule in force.
 */
class CreditScoreService
{
    /**
     * @return array{score: int, band: string|null, on_time: int, late: int, missed: int}
     */
    public function compute(Customer $customer, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::today();

        $loanIds = LoanParty::where('customer_id', $customer->id)->whereIn('role', PartyRole::borrowingValues())->pluck('loan_id');

        $installments = LoanInstallment::whereIn('loan_id', $loanIds)
            ->where('status', '!=', InstallmentStatus::Rescheduled->value)
            // Paid lines count at once; unpaid ones only once their grace period is over.
            ->where(fn ($q) => $q->where('status', InstallmentStatus::Paid->value)->orWhere('grace_ends_on', '<', $asOf->toDateString()))
            ->get(['status', 'grace_ends_on', 'settled_on']);

        $onTime = $late = $missed = 0;

        foreach ($installments as $installment) {
            if ($installment->status !== InstallmentStatus::Paid) {
                $missed++;
            } elseif ($installment->settled_on->lessThanOrEqualTo($installment->grace_ends_on)) {
                $onTime++;
            } else {
                $late++;
            }
        }

        $score = (int) Setting::value('credit_score.base', 500)
            + $onTime * (int) Setting::value('credit_score.points_on_time', 10)
            - $late * (int) Setting::value('credit_score.points_late', 10)
            - $missed * (int) Setting::value('credit_score.points_missed', 10);

        $settled = $onTime + $late;
        $share = $settled > 0 ? $onTime / $settled * 100 : null;

        return [
            'score' => max(0, min(1000, $score)),
            'band' => match (true) {
                $share === null => null,
                $share >= 90 => 'A',
                $share >= 75 => 'B',
                $share >= 50 => 'C',
                default => 'D',
            },
            'on_time' => $onTime,
            'late' => $late,
            'missed' => $missed,
        ];
    }

    /**
     * Store the score on the customer (shown on screens and used in lists).
     */
    public function recompute(Customer $customer, ?CarbonImmutable $asOf = null): int
    {
        $score = $this->compute($customer, $asOf)['score'];
        $customer->forceFill(['credit_score' => $score])->saveQuietly();

        return $score;
    }
}
