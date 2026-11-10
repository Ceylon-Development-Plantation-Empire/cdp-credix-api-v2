<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\InstallmentStatus;
use App\Enums\PartyRole;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanParty;
use App\Models\Setting;
use App\Support\BusinessDate;
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
     * The score with the record behind it: per loan, how many installments were paid on time,
     * late or missed, and the worst number of days an installment ran past its due date.
     *
     * @return array{score: int, band: string|null, on_time: int, late: int, missed: int, worst_days_late: int, loans: list<array<string, mixed>>}
     */
    public function compute(Customer $customer, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= BusinessDate::todayImmutable();

        $loanIds = LoanParty::where('customer_id', $customer->id)->whereIn('role', PartyRole::borrowingValues())->pluck('loan_id');

        $installments = LoanInstallment::whereIn('loan_id', $loanIds)
            ->where('status', '!=', InstallmentStatus::Rescheduled->value)
            // Paid lines count at once; unpaid ones only once their grace period is over.
            ->where(fn ($q) => $q->where('status', InstallmentStatus::Paid->value)->orWhere('grace_ends_on', '<', $asOf->toDateString()))
            ->get(['loan_id', 'status', 'due_date', 'grace_ends_on', 'settled_on']);

        $onTime = $late = $missed = 0;
        $perLoan = [];

        foreach ($installments as $installment) {
            $row = &$perLoan[$installment->loan_id];
            $row ??= ['on_time' => 0, 'late' => 0, 'missed' => 0, 'worst_days_late' => 0];

            if ($installment->status !== InstallmentStatus::Paid) {
                $missed++;
                $row['missed']++;
                $daysLate = (int) $installment->due_date->diffInDays($asOf);
            } elseif ($installment->settled_on->lessThanOrEqualTo($installment->grace_ends_on)) {
                $onTime++;
                $row['on_time']++;
                $daysLate = max(0, (int) $installment->due_date->diffInDays($installment->settled_on, false));
            } else {
                $late++;
                $row['late']++;
                $daysLate = (int) $installment->due_date->diffInDays($installment->settled_on);
            }

            $row['worst_days_late'] = max($row['worst_days_late'], $daysLate);
            unset($row);
        }

        $loans = Loan::withoutGlobalScope('branch')->with('product:id,name')->whereIn('id', $loanIds)->latest('id')
            ->get(['id', 'public_id', 'application_no', 'loan_no', 'product_id', 'status', 'days_past_due'])
            ->map(fn (Loan $loan) => [
                'public_id' => $loan->public_id,
                'application_no' => $loan->application_no,
                'loan_no' => $loan->loan_no,
                'product' => $loan->product?->name,
                'status' => $loan->status->value,
                'days_past_due' => $loan->days_past_due,
            ] + ($perLoan[$loan->id] ?? ['on_time' => 0, 'late' => 0, 'missed' => 0, 'worst_days_late' => 0]))
            ->values()->all();

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
            'worst_days_late' => (int) max([0, ...array_column($perLoan, 'worst_days_late')]),
            'loans' => $loans,
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
