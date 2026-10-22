<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\AllocationComponent;
use App\Enums\ApprovalAction;
use App\Enums\ChargeBehaviour;
use App\Enums\ChargeCalculation;
use App\Enums\ChargeRecurrence;
use App\Enums\FinanceBasis;
use App\Enums\LoanStatus;
use App\Enums\PaymentChannel;
use App\Enums\TransactionType;
use App\Exceptions\BusinessRuleException;
use App\Finance\Allocation\AllocationLine;
use App\Finance\Penalty\PenaltyCalculator;
use App\Finance\Support\Money;
use App\Models\ApprovalRequest;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Approvals\ApprovalRequestService;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;

/**
 * Late payment charges (design 6.5, decision D-4) and their waiver.
 *
 * The rule comes from the product's late_payment charge (frozen in the loan's terms) or, when the
 * product has none, from the penalty.* settings: fixed or % of the overdue installment, charged once
 * when grace ends or again every month while unpaid. A penalty is worked out on the installment
 * without its earlier penalties, so it never compounds. Islamic late charges go to charity.
 */
class PenaltyService
{
    public function __construct(
        protected PenaltyCalculator $calculator,
        protected LedgerService $ledger,
        protected LoanBalanceService $balances,
        protected LoanPricingService $pricing,
        protected ApprovalRequestService $approvals,
    ) {}

    /**
     * Charge every penalty that has fallen due up to the business date. Safe to run again:
     * last_penalty_on means a date is never charged twice.
     *
     * @return int number of penalties raised
     */
    public function apply(Loan $loan, CarbonImmutable $businessDate): int
    {
        $rule = $this->rule($loan);
        $raised = 0;

        foreach ($loan->installments()->current()->get() as $installment) {
            if (! $installment->unpaidExcludingPenalty()->isPositive()) {
                continue;
            }

            $graceDays = (int) $installment->due_date->diffInDays($installment->grace_ends_on);
            $dates = $this->calculator->chargeDates(
                CarbonImmutable::parse($installment->due_date),
                $graceDays,
                $rule['recurrence'],
                $installment->last_penalty_on ? CarbonImmutable::parse($installment->last_penalty_on) : null,
                $businessDate,
            );

            foreach ($dates as $date) {
                $amount = $this->calculator->amount($rule['calculation'], $rule['value'], $installment->unpaidExcludingPenalty(), $rule['min'], $rule['max']);

                if ($amount->isPositive()) {
                    $this->ledger->raise($loan, $installment, AllocationComponent::Penalty, $amount, $date, 'Late payment charge', $rule['is_charity'], ChargeBehaviour::LatePayment);
                    $raised++;
                }

                $installment->last_penalty_on = $date->toDateString();
                $installment->save();
            }
        }

        return $raised;
    }

    /**
     * Ask for a waiver of (part of) an installment's late charges.
     */
    public function requestWaiver(Loan $loan, int $installmentNo, string $amount, string $reason, User $by): ApprovalRequest
    {
        $installment = $this->installment($loan, $installmentNo);
        $this->assertWaivable($loan, $installment, $amount);
        $this->approvals->assertNonePending($loan, ApprovalAction::PenaltyWaiver);

        return $this->approvals->create($loan, ApprovalAction::PenaltyWaiver, ['installment_no' => $installmentNo, 'amount' => (string) Money::of($amount)], $reason, $by);
    }

    /**
     * Apply an approved waiver (called by PenaltyWaiverHandler inside the decision transaction).
     */
    public function applyWaiver(Loan $loan, int $installmentNo, string $amount, string $reason, User $approver, int $requestedBy): void
    {
        $installment = $this->installment($loan, $installmentNo);
        $this->assertWaivable($loan, $installment, $amount);

        $this->ledger->credit($loan, TransactionType::Waiver, Money::of($amount), [new AllocationLine($installment->id, AllocationComponent::Penalty, Money::of($amount))], [
            'value_date' => BusinessDate::today()->toDateString(),
            'channel' => PaymentChannel::System,
            'narration' => "Penalty waiver (installment {$installmentNo}): {$reason}",
            'posted_by' => $requestedBy,
            'approved_by' => $approver->id,
        ]);

        $this->balances->refresh($loan);
        $this->balances->closeIfPaid($loan, $approver);
    }

    /**
     * @return array{calculation: ChargeCalculation, value: string, min: string|null, max: string|null, recurrence: ChargeRecurrence, is_charity: bool}
     */
    public function rule(Loan $loan): array
    {
        $charge = collect($this->pricing->termsOf($loan)['charges'])->firstWhere('behaviour', ChargeBehaviour::LatePayment->value);
        $islamic = $loan->finance_basis === FinanceBasis::Islamic;

        if ($charge) {
            return [
                'calculation' => ChargeCalculation::from($charge['calculation']),
                'value' => $charge['value'],
                'min' => $charge['min_amount'],
                'max' => $charge['max_amount'],
                'recurrence' => ChargeRecurrence::from($charge['recurrence']),
                'is_charity' => $islamic || $charge['is_charity'],
            ];
        }

        return [
            'calculation' => ChargeCalculation::from(Setting::value('penalty.calculation', 'percent_of_overdue')),
            'value' => (string) Setting::value('penalty.value', '2'),
            'min' => null,
            'max' => null,
            'recurrence' => ChargeRecurrence::from(Setting::value('penalty.recurrence', 'monthly_while_overdue')),
            'is_charity' => $islamic,
        ];
    }

    protected function installment(Loan $loan, int $installmentNo): LoanInstallment
    {
        $installment = $loan->installments()->current()->where('installment_no', $installmentNo)->first();

        if (! $installment) {
            throw new BusinessRuleException('INSTALLMENT_NOT_FOUND', 'Installment not found on the current schedule.', 404);
        }

        return $installment;
    }

    protected function assertWaivable(Loan $loan, LoanInstallment $installment, string $amount): void
    {
        if ($loan->status !== LoanStatus::Active) {
            throw new BusinessRuleException('LOAN_NOT_ACTIVE', 'Penalties can only be waived on an active loan.', 409);
        }

        $open = $installment->unpaid(AllocationComponent::Penalty);

        if (Money::of($amount)->isGreaterThan($open)) {
            throw new BusinessRuleException('WAIVER_EXCEEDS_PENALTY', "Only {$open} of late charges is unpaid on this installment.", 422);
        }
    }
}
