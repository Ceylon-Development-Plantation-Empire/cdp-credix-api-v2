<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\AllocationComponent;
use App\Enums\ClosureReason;
use App\Enums\FinanceBasis;
use App\Enums\LoanStatus;
use App\Enums\RecoveryStatus;
use App\Finance\Allocation\OutstandingInstallment;
use App\Finance\Delinquency\DelinquencyClassifier;
use App\Finance\Support\Money;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\RecoveryCase;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\LoanPostingService;
use App\Support\BusinessDate;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The balances shown on a loan (outstanding, arrears, days past due, risk stage) are a cache.
 * This service rebuilds them from the schedule after every posting and every night, so they
 * can never drift from what is actually owed (the old system's outstanding_balance did).
 */
class LoanBalanceService
{
    public function __construct(
        protected LoanWorkflowService $workflow,
        protected LoanPostingService $posting,
    ) {}

    /**
     * @return Collection<int, LoanInstallment> unpaid lines of the schedule in force, oldest first
     */
    public function unpaidInstallments(Loan $loan): Collection
    {
        return $loan->installments()->current()->get()
            ->filter(fn (LoanInstallment $i) => $i->unpaidTotal()->isPositive())
            ->sortBy(fn (LoanInstallment $i) => [$i->due_date->timestamp, $i->installment_no])
            ->values();
    }

    /**
     * Everything the borrower owes on the schedule in force right now (principal + profit +
     * charges + penalty, less waivers), read from the database. Used for transactions'
     * balance_after: the caller adds or subtracts the movement it is about to write.
     */
    public static function owed(Loan $loan): BigDecimal
    {
        return Money::sum(
            LoanInstallment::query()->where('loan_id', $loan->id)->current()->get()
                ->map(fn (LoanInstallment $i) => $i->unpaidTotal())
        );
    }

    /**
     * The installments in the form the payment allocator works with.
     *
     * @param  Collection<int, LoanInstallment>  $installments
     * @return list<OutstandingInstallment>
     */
    public function forAllocator(Collection $installments): array
    {
        return $installments->map(fn (LoanInstallment $i) => new OutstandingInstallment(
            $i->id,
            CarbonImmutable::parse($i->due_date),
            [
                AllocationComponent::Principal->value => $i->unpaid(AllocationComponent::Principal),
                AllocationComponent::Profit->value => $i->unpaid(AllocationComponent::Profit),
                AllocationComponent::Charge->value => $i->unpaid(AllocationComponent::Charge),
                AllocationComponent::Penalty->value => $i->unpaid(AllocationComponent::Penalty),
            ],
        ))->all();
    }

    /**
     * Payment waterfall for the loan's basis (settings allocation.order.*, design 6.4).
     *
     * @return list<AllocationComponent>
     */
    public function allocationOrder(Loan $loan): array
    {
        $configured = Setting::value("allocation.order.{$loan->finance_basis->value}");

        return is_array($configured) && $configured !== []
            ? array_map(fn (string $c) => AllocationComponent::from($c), $configured)
            : AllocationComponent::defaultOrder($loan->finance_basis ?? FinanceBasis::Conventional);
    }

    /**
     * Rebuild installment statuses and the loan's balance cache as of a business date.
     */
    public function refresh(Loan $loan, ?CarbonImmutable $businessDate = null): Loan
    {
        $date = $businessDate ?? BusinessDate::todayImmutable();
        $installments = $loan->installments()->current()->get();

        foreach ($installments as $installment) {
            $installment->syncStatus($date);
            $installment->save();
        }

        $sum = fn (AllocationComponent $c) => (string) Money::sum($installments->map(fn (LoanInstallment $i) => $i->unpaid($c)));
        $pastDue = $installments->filter(fn (LoanInstallment $i) => $i->due_date->lessThan($date) && $i->unpaidTotal()->isPositive());

        $classifier = new DelinquencyClassifier(
            (int) Setting::value('risk.stage_two_after_dpd', 30),
            (int) Setting::value('risk.stage_three_after_dpd', 90),
        );

        $oldest = $pastDue->min('due_date');
        $dpd = $classifier->daysPastDue($oldest ? CarbonImmutable::parse($oldest) : null, $date);

        $loan->forceFill([
            'outstanding_principal' => $sum(AllocationComponent::Principal),
            'outstanding_profit' => $sum(AllocationComponent::Profit),
            'outstanding_charges' => $sum(AllocationComponent::Charge),
            'outstanding_penalty' => $sum(AllocationComponent::Penalty),
            'arrears_amount' => (string) Money::sum($pastDue->map(fn (LoanInstallment $i) => $i->unpaidTotal())),
            'days_past_due' => $dpd,
            'risk_stage' => $classifier->riskStage($dpd),
        ])->save();

        // Arrears cleared: the recovery case has done its job (it can open again if arrears return).
        // A written-off loan owes nothing on its schedule, but its recovery goes on.
        if ($dpd === 0 && $loan->status === LoanStatus::Active) {
            RecoveryCase::withoutGlobalScope('branch')->where('loan_id', $loan->id)->open()->update([
                'status' => RecoveryStatus::Resolved->value,
                'open_case_key' => null,
                'closed_at' => now(),
                'outcome' => 'Arrears cleared',
            ]);
        }

        if ($loan->isMemberLoan()) {
            $this->refreshFacility($loan->parent_id);
        }

        return $loan;
    }

    public function isFullyPaid(Loan $loan): bool
    {
        return $this->unpaidInstallments($loan)->isEmpty();
    }

    /**
     * Close the loan once nothing is owed any more.
     */
    public function closeIfPaid(Loan $loan, ?User $by, ClosureReason $reason = ClosureReason::Settled): bool
    {
        if ($loan->status !== LoanStatus::Active || ! $this->isFullyPaid($loan)) {
            return false;
        }

        $this->workflow->closeLoan($loan, LoanStatus::Settled, $by, $reason === ClosureReason::EarlySettled ? 'Settled early' : 'Fully repaid', $reason);
        $this->posting->releaseSuspense($loan, BusinessDate::todayImmutable(), 'loan paid off');

        return true;
    }

    /**
     * A group facility shows the sum of its member loans, and the worst arrears among them.
     */
    protected function refreshFacility(int $facilityId): void
    {
        $facility = Loan::withoutGlobalScope('branch')->find($facilityId);
        $members = $facility->memberLoans()->withoutGlobalScope('branch')->where('status', '!=', LoanStatus::Cancelled->value)->get();

        $total = fn (string $column) => (string) Money::sum($members->pluck($column));

        $facility->forceFill([
            'outstanding_principal' => $total('outstanding_principal'),
            'outstanding_profit' => $total('outstanding_profit'),
            'outstanding_charges' => $total('outstanding_charges'),
            'outstanding_penalty' => $total('outstanding_penalty'),
            'arrears_amount' => $total('arrears_amount'),
            'days_past_due' => (int) $members->max('days_past_due'),
            'risk_stage' => (int) $members->max('risk_stage'),
        ])->save();
    }
}
