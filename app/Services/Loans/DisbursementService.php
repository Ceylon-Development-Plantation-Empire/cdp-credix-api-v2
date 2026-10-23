<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\CollateralStatus;
use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Enums\PaymentChannel;
use App\Enums\TransactionType;
use App\Exceptions\BusinessRuleException;
use App\Finance\Schedule\ScheduleLine;
use App\Finance\Support\Money;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\LoanPostingService;
use App\Support\BusinessDate;
use App\Traits\ActivityLogTrait;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pays the loan out (rule L-7) and creates its repayment schedule.
 *
 * Before any money moves:
 * - the loan is accepted, and every borrower's OTP acceptance still matches the approved terms;
 * - the person disbursing did not approve the loan;
 * - every collateral is perfected (bond registered, CR endorsed, gold in the vault);
 * - every borrower confirmed their details and the terms on the review link (OTP), or with an officer, and
 *   nothing changed since. This replaces the signed paper agreement.
 *
 * Due dates count from the disbursement date, never from approval (design 6.3). The request
 * carries an Idempotency-Key: a retried or double-clicked disbursement returns the first result
 * instead of paying twice.
 */
class DisbursementService
{
    use ActivityLogTrait;

    public function __construct(
        protected LoanWorkflowService $workflow,
        protected LoanApplicationService $applications,
        protected LoanPricingService $pricing,
        protected LoanPartyService $parties,
        protected LoanChecklistService $checklist,
        protected LoanPostingService $posting,
        protected SafekeepingFeeService $safekeeping,
        protected LoanReviewLinkService $reviews,
    ) {}

    /**
     * @param  array{channel: string, external_reference?: string|null, remarks?: string|null}  $data
     */
    public function disburse(Loan $loan, array $data, string $idempotencyKey, User $by): Loan
    {
        return DB::transaction(function () use ($loan, $data, $idempotencyKey, $by) {
            $loan = $this->workflow->lock($loan);
            $borrowingLoans = $this->applications->borrowingLoans($loan);

            if ($loan->status === LoanStatus::Active && $this->alreadyDisbursedWith($borrowingLoans, $idempotencyKey)) {
                return $loan;
            }

            if ($loan->isMemberLoan() || ! $loan->status->canMoveTo(LoanStatus::Active)) {
                throw new BusinessRuleException('LOAN_INVALID_TRANSITION', 'Only an accepted loan can be disbursed.', 409);
            }

            $this->workflow->assertSegregation($loan, $by, ['approved' => LoanStatus::Approved]);
            $this->assertReadyToDisburse($loan, $borrowingLoans);

            $terms = $this->pricing->termsOf($loan);
            $graceDays = (int) ($terms['grace_period_days'] ?? Setting::value('repayment.grace_period_days', 7));
            $today = BusinessDate::todayImmutable();
            $channel = PaymentChannel::from($data['channel']);
            $txnNos = [];

            foreach ($borrowingLoans as $each) {
                $txnNos = [...$txnNos, ...$this->disburseOne($each, $today, $graceDays, $channel, $data['external_reference'] ?? null, $idempotencyKey, $by)];
            }

            if ($loan->isGroupFacility()) {
                $this->applications->sumMemberColumns($loan, $borrowingLoans);
                $loan->forceFill([
                    'loan_no' => $this->loanNo($loan),
                    'first_due_date' => $borrowingLoans->min('first_due_date'),
                    'maturity_date' => $borrowingLoans->max('maturity_date'),
                    'outstanding_principal' => (string) Money::sum($borrowingLoans->pluck('outstanding_principal')),
                    'outstanding_profit' => (string) Money::sum($borrowingLoans->pluck('outstanding_profit')),
                    'outstanding_charges' => (string) Money::sum($borrowingLoans->pluck('outstanding_charges')),
                ])->save();
            }

            $this->logActivity('DISBURSE', 'Loan', "Disbursed {$loan->application_no}", ['transactions' => $txnNos]);

            $loan = $this->workflow->transition($loan, LoanStatus::Active, $by, $data['remarks'] ?? null, ['transactions' => $txnNos]);

            // Gold kept in the vault: the first month's safekeeping fee is owed from today.
            foreach ($borrowingLoans as $each) {
                $each->refresh();

                if ($this->safekeeping->apply($each, $today) > 0) {
                    app(LoanBalanceService::class)->refresh($each);
                }
            }

            return $loan->refresh();
        }, attempts: 3);
    }

    /**
     * Schedule, ledger entries and loan number of one borrowing loan.
     *
     * @return list<string> transaction numbers
     */
    protected function disburseOne(Loan $loan, CarbonImmutable $today, int $graceDays, PaymentChannel $channel, ?string $reference, string $idempotencyKey, User $by): array
    {
        $pricing = $this->pricing->price($loan, $loan->approved_amount, $loan->rate, $today);
        $schedule = $pricing->schedule;
        $last = $schedule->lastLine()->installmentNo;

        foreach ($schedule->lines as $line) {
            /** @var ScheduleLine $line */
            LoanInstallment::create([
                'loan_id' => $loan->id,
                'schedule_version' => 1,
                'installment_no' => $line->installmentNo,
                'due_date' => $line->dueDate->toDateString(),
                // A lease's residual (balloon) value is paid with the last installment.
                'principal_due' => (string) ($line->installmentNo === $last ? $line->principal->plus($schedule->residualValue) : $line->principal),
                'profit_due' => (string) $line->profit,
                'charges_due' => (string) ($line->installmentNo === 1 ? $pricing->firstInstallmentCharges : Money::zero()),
                'status' => InstallmentStatus::Upcoming,
                'grace_ends_on' => $line->dueDate->addDays($graceDays)->toDateString(),
            ]);
        }

        // The schedule now exists: what is owed after disbursement is the whole booked receivable.
        $owed = (string) LoanBalanceService::owed($loan);

        $disbursement = LoanTransaction::create([
            'loan_id' => $loan->id,
            'type' => TransactionType::Disbursement,
            'amount' => (string) $pricing->amount,
            'balance_after' => $owed,
            'value_date' => $today->toDateString(),
            'channel' => $channel,
            'external_reference' => $reference,
            'idempotency_key' => $this->keyFor($loan, $idempotencyKey),
            'narration' => 'Loan disbursed',
            'posted_by' => $by->id,
        ]);

        $txnNos = [$disbursement->txn_no];
        $deduction = null;

        if ($pricing->deductedCharges->isPositive()) {
            $deduction = LoanTransaction::create([
                'loan_id' => $loan->id,
                'type' => TransactionType::Charge,
                'amount' => (string) $pricing->deductedCharges,
                // Collected at source from the payout: the borrower's balance does not change.
                'balance_after' => $owed,
                'value_date' => $today->toDateString(),
                'channel' => PaymentChannel::Deduction,
                'narration' => 'Upfront charges deducted from the disbursement',
                'posted_by' => $by->id,
            ]);
            $txnNos[] = $deduction->txn_no;
        }

        $loan->forceFill($pricing->columns() + [
            'loan_no' => $this->loanNo($loan),
            'first_due_date' => $schedule->lines[0]->dueDate->toDateString(),
            'maturity_date' => $schedule->maturityDate()->toDateString(),
            'schedule_starts_on' => $today->toDateString(),
            'outstanding_principal' => (string) $pricing->amount,
            'outstanding_profit' => (string) $schedule->totalProfit(),
            'outstanding_charges' => (string) $pricing->firstInstallmentCharges,
            'arrears_amount' => '0',
            'days_past_due' => 0,
            'risk_stage' => 1,
        ])->save();

        // Posted after the loan number is set, so the journals carry it.
        $this->posting->postTransaction($disbursement, $pricing->firstInstallmentCharges);

        if ($deduction) {
            $this->posting->postTransaction($deduction);
        }

        return $txnNos;
    }

    /**
     * @param  Collection<int, Loan>  $borrowingLoans
     */
    protected function assertReadyToDisburse(Loan $loan, Collection $borrowingLoans): void
    {
        $problems = [];

        foreach ($borrowingLoans as $each) {
            foreach ($each->borrowingParties()->with(['customer', 'loan'])->whereNull('released_at')->get() as $party) {
                if (! $this->parties->hasCurrentAcceptance($party)) {
                    $problems[] = ['code' => 'OFFER_NOT_ACCEPTED', 'message' => 'The borrower has not accepted the approved terms by OTP.', 'customer_no' => $party->customer->customer_no];
                }
            }
        }

        // On unless the business switches it off (setting loan_review.required_before_disbursement): the customer
        // confirmed their details and the terms on the review link, or with an officer, and nothing changed
        // since. A customer who asked for a correction has not confirmed, so the loan waits for it.
        if (Setting::value('loan_review.required_before_disbursement', true)) {
            foreach ($borrowingLoans as $each) {
                foreach ($this->reviews->recipients($each) as $party) {
                    if (! $this->reviews->isConfirmed($party)) {
                        $problems[] = ['code' => 'REVIEW_NOT_CONFIRMED', 'message' => 'The customer has not confirmed their details and the loan terms (review link).', 'customer_no' => $party->customer->customer_no];
                    }
                }
            }
        }

        $collaterals = $loan->collaterals()->whereIn('status', [CollateralStatus::Proposed->value, CollateralStatus::Verified->value, CollateralStatus::Perfected->value])->get();

        if ($collaterals->contains(fn ($c) => $c->status !== CollateralStatus::Perfected)) {
            $problems[] = ['code' => 'COLLATERAL_NOT_PERFECTED', 'message' => 'Every collateral must be perfected (registered or in custody) before disbursement.'];
        }

        if ($problems) {
            throw new BusinessRuleException('LOAN_NOT_READY', 'The loan cannot be disbursed yet. See the problems listed.', 422, ['problems' => $problems]);
        }
    }

    /**
     * @param  Collection<int, Loan>  $borrowingLoans
     */
    protected function alreadyDisbursedWith(Collection $borrowingLoans, string $idempotencyKey): bool
    {
        $first = $borrowingLoans->first();

        return $first && LoanTransaction::where('idempotency_key', $this->keyFor($first, $idempotencyKey))->exists();
    }

    /**
     * The client's key, scoped to the loan and hashed (it is never stored as sent).
     */
    protected function keyFor(Loan $loan, string $idempotencyKey): string
    {
        return hash('sha256', "disbursement|{$loan->id}|{$idempotencyKey}");
    }

    protected function loanNo(Loan $loan): string
    {
        return Loan::nextLoanNo();
    }
}
