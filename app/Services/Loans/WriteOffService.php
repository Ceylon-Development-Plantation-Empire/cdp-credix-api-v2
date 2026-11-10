<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\AllocationComponent;
use App\Enums\ApprovalAction;
use App\Enums\ClosureReason;
use App\Enums\LoanStatus;
use App\Enums\PaymentChannel;
use App\Enums\TransactionType;
use App\Exceptions\BusinessRuleException;
use App\Finance\Allocation\AllocationLine;
use App\Finance\Support\Money;
use App\Models\ApprovalRequest;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\LoanPostingService;
use App\Services\Approvals\ApprovalRequestService;
use App\Support\BusinessDate;
use App\Support\Idempotency;
use App\Traits\ActivityLogTrait;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Write-off (through maker-checker) and recovery of written-off debt.
 *
 * A write-off takes what is still owed off the books (charged to the loan's provision first) and
 * closes the loan as written_off. It is accounting only: the collateral, the guarantees, the
 * borrower's open-loan key and the recovery case stay, and collection goes on. Money recovered
 * afterwards is posted as recovery income; once the written-off amount is recovered in full the
 * securities are released.
 */
class WriteOffService
{
    use ActivityLogTrait;

    public function __construct(
        protected LoanWorkflowService $workflow,
        protected LoanBalanceService $balances,
        protected LedgerService $ledger,
        protected LoanPostingService $posting,
        protected ApprovalRequestService $approvals,
    ) {}

    /**
     * Maker: the loan must be far enough behind (write_off.min_dpd).
     */
    public function request(Loan $loan, string $reason, User $by): ApprovalRequest
    {
        return DB::transaction(function () use ($loan, $reason, $by) {
            $loan = $this->workflow->lock($loan);
            $this->assertWritable($loan);
            $this->approvals->assertNonePending($loan, ApprovalAction::WriteOff);

            return $this->approvals->create($loan, ApprovalAction::WriteOff, [
                'days_past_due' => $loan->days_past_due,
                'outstanding' => (string) $this->owed($loan),
            ], $reason, $by);
        }, attempts: 3);
    }

    /**
     * Checker (called by WriteOffHandler inside the decision transaction). Payments may have come
     * in while the request waited, so the amount is worked out again now.
     */
    public function apply(Loan $loan, string $reason, User $approver, int $requestedBy): LoanTransaction
    {
        $loan = $this->workflow->lock($loan);
        $this->assertWritable($loan);

        $lines = [];

        foreach ($this->balances->unpaidInstallments($loan) as $installment) {
            /** @var LoanInstallment $installment */
            foreach ([AllocationComponent::Principal, AllocationComponent::Profit, AllocationComponent::Charge, AllocationComponent::Penalty] as $component) {
                $unpaid = $installment->unpaid($component);

                if ($unpaid->isPositive()) {
                    $lines[] = new AllocationLine($installment->id, $component, $unpaid);
                }
            }
        }

        $amount = Money::sum(array_map(fn (AllocationLine $l) => $l->amount, $lines));

        $transaction = $this->ledger->credit($loan, TransactionType::WriteOff, $amount, $lines, [
            'value_date' => BusinessDate::today()->toDateString(),
            'channel' => PaymentChannel::System,
            'narration' => "Written off: {$reason}",
            'posted_by' => $requestedBy,
            'approved_by' => $approver->id,
        ]);

        // The write-off used whatever was in suspense; the loan no longer earns.
        $loan->forceFill(['income_suspended_at' => null])->save();

        $this->workflow->closeLoan($loan, LoanStatus::WrittenOff, $approver, $reason, ClosureReason::WrittenOff);
        $this->balances->refresh($loan->refresh());

        $this->logActivity('WRITE_OFF', 'Loan', "Wrote off {$amount} on {$loan->loan_no}", ['transaction' => $transaction->txn_no]);

        return $transaction;
    }

    /**
     * Money collected on a written-off loan. Never more than is still unrecovered.
     *
     * @param  array{amount: string, channel: string, external_reference?: string|null}  $data
     */
    public function recover(Loan $loan, array $data, string $idempotencyKey, User $by): LoanTransaction
    {
        $keyHash = hash('sha256', "recovery|{$loan->id}|{$idempotencyKey}");

        return DB::transaction(function () use ($loan, $data, $keyHash, $by) {
            $loan = $this->workflow->lock($loan);

            if ($existing = Idempotency::replay($keyHash, $data['amount'])) {
                return $existing;
            }

            if ($loan->status !== LoanStatus::WrittenOff) {
                throw new BusinessRuleException('LOAN_NOT_WRITTEN_OFF', 'Recoveries are posted on written-off loans only. Use a normal payment on an active loan.', 409);
            }

            $unrecovered = $this->unrecovered($loan);
            $amount = Money::of($data['amount']);

            if ($amount->isGreaterThan($unrecovered)) {
                throw new BusinessRuleException('RECOVERY_EXCEEDS_BALANCE', "Only {$unrecovered} of the written-off amount is still unrecovered.", 422, [
                    'unrecovered' => (string) $unrecovered,
                ]);
            }

            $transaction = LoanTransaction::create([
                'loan_id' => $loan->id,
                'type' => TransactionType::Recovery,
                'amount' => (string) $amount,
                // A written-off loan owes nothing on its schedule; the recovery is income of its own.
                'balance_after' => (string) LoanBalanceService::owed($loan),
                'value_date' => BusinessDate::today()->toDateString(),
                'channel' => PaymentChannel::from($data['channel']),
                'external_reference' => $data['external_reference'] ?? null,
                'idempotency_key' => $keyHash,
                'narration' => 'Recovery of written-off loan',
                'posted_by' => $by->id,
            ]);
            $this->posting->postTransaction($transaction);

            if ($amount->isEqualTo($unrecovered)) {
                $this->workflow->releaseRecovered($loan, $by);
            }

            $this->logActivity('RECOVERY', 'Loan', "Recovery {$transaction->txn_no} of {$amount} on {$loan->loan_no}");

            return $transaction;
        }, attempts: 3);
    }

    /**
     * Written off and not yet recovered.
     */
    public function unrecovered(Loan $loan): BigDecimal
    {
        $sum = fn (TransactionType $type) => Money::of((string) (LoanTransaction::where('loan_id', $loan->id)->where('type', $type->value)->sum('amount') ?? 0));

        return $sum(TransactionType::WriteOff)->minus($sum(TransactionType::Recovery));
    }

    protected function assertWritable(Loan $loan): void
    {
        if ($loan->isGroupFacility()) {
            throw new BusinessRuleException('WRITE_OFF_MEMBER_LOAN', 'Each member loan of a group is written off on its own.', 422);
        }

        if ($loan->status !== LoanStatus::Active) {
            throw new BusinessRuleException('LOAN_NOT_ACTIVE', 'Only an active loan can be written off.', 409);
        }

        $minDpd = (int) Setting::value('write_off.min_dpd', 360);

        if ($loan->days_past_due < $minDpd) {
            throw new BusinessRuleException('WRITE_OFF_TOO_EARLY', "A loan can be written off once it is {$minDpd} days past due (now {$loan->days_past_due}).", 422, [
                'min_days_past_due' => $minDpd,
                'days_past_due' => $loan->days_past_due,
            ]);
        }

        if ($loan->approvalRequests()->pending()->where('action', ApprovalAction::Restructure->value)->exists()) {
            throw new BusinessRuleException('RESTRUCTURE_PENDING', 'A restructure is waiting for approval.', 409);
        }
    }

    protected function owed(Loan $loan): BigDecimal
    {
        return Money::sum($this->balances->unpaidInstallments($loan)->map->unpaidTotal());
    }
}
