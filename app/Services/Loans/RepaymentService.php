<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\ApprovalAction;
use App\Enums\LoanStatus;
use App\Enums\PartyRole;
use App\Enums\PaymentChannel;
use App\Enums\TransactionType;
use App\Events\PaymentPosted;
use App\Exceptions\BusinessRuleException;
use App\Finance\Allocation\AllocationLine;
use App\Finance\Allocation\PaymentAllocator;
use App\Finance\Support\Money;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanParty;
use App\Models\LoanTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Services\Approvals\ApprovalRequestService;
use App\Support\BusinessDate;
use App\Support\Idempotency;
use App\Traits\ActivityLogTrait;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Repayments and their reversal (design 6.4).
 *
 * 1. Lock the loan; it must be active with no restructure waiting.
 * 2. Oldest unpaid installment first, in the waterfall order of the loan's basis.
 * 3. A payment above everything owed is refused (no unexplained credit balances; an early
 *    payoff goes through early settlement, which gives the interest rebate).
 * 4. Ledger + allocations, then the balance cache is rebuilt and the loan closes if paid off.
 * 5. A guarantor paying marks the guarantee as invoked (rule G-3).
 *
 * The Idempotency-Key header makes a retry return the first result instead of posting twice.
 */
class RepaymentService
{
    use ActivityLogTrait;

    public function __construct(
        protected LoanWorkflowService $workflow,
        protected LoanBalanceService $balances,
        protected LedgerService $ledger,
        protected PaymentAllocator $allocator,
        protected ApprovalRequestService $approvals,
    ) {}

    /**
     * Post a payment, or send it for approval when it is dated further back than allowed.
     *
     * @param  array{amount: string, value_date?: string|null, channel: string, external_reference?: string|null, payer_customer_public_id?: string|null, narration?: string|null}  $data
     */
    public function receive(Loan $loan, array $data, string $idempotencyKey, User $by): LoanTransaction|ApprovalRequest
    {
        $keyHash = $this->keyFor($loan, $idempotencyKey);

        if ($existing = Idempotency::replay($keyHash, $data['amount'])) {
            return $existing;
        }

        $valueDate = CarbonImmutable::parse($data['value_date'] ?? BusinessDate::today());
        $maxBackdate = (int) Setting::value('payment.max_backdate_days', 0);

        if ($valueDate->lessThan(BusinessDate::todayImmutable()->subDays($maxBackdate))) {
            return DB::transaction(function () use ($loan, $data, $keyHash, $by) {
                $loan = $this->workflow->lock($loan);
                $this->assertPayable($loan);

                $pending = $loan->approvalRequests()->pending()->where('action', ApprovalAction::BackdatedPayment->value)->get()
                    ->first(fn (ApprovalRequest $r) => ($r->payload['idempotency_key'] ?? null) === $keyHash);

                return $pending ?? $this->approvals->create($loan, ApprovalAction::BackdatedPayment, $data + ['idempotency_key' => $keyHash], $data['narration'] ?? 'Back-dated payment', $by);
            }, attempts: 3);
        }

        return $this->post($loan, $data, $keyHash, $by);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function post(Loan $loan, array $data, string $keyHash, User $by, ?User $approvedBy = null): LoanTransaction
    {
        return DB::transaction(function () use ($loan, $data, $keyHash, $by, $approvedBy) {
            $loan = $this->workflow->lock($loan);

            if ($existing = Idempotency::replay($keyHash, $data['amount'])) {
                return $existing;
            }

            $this->assertPayable($loan);

            $amount = Money::of($data['amount']);
            $valueDate = CarbonImmutable::parse($data['value_date'] ?? BusinessDate::today());

            if ($valueDate->lessThan(BusinessDate::of($loan->disbursed_at))) {
                throw new BusinessRuleException('VALUE_DATE_BEFORE_DISBURSEMENT', 'A payment cannot be dated before the loan was disbursed.', 422);
            }

            $payer = $this->payer($loan, $data['payer_customer_public_id'] ?? null);
            $installments = $this->balances->unpaidInstallments($loan);
            $owed = Money::sum($installments->map->unpaidTotal());

            if ($amount->isGreaterThan($owed)) {
                throw new BusinessRuleException('PAYMENT_EXCEEDS_BALANCE', "The payment is more than the {$owed} owed. To close the loan early use early settlement.", 422, [
                    'outstanding' => (string) $owed,
                ]);
            }

            $lines = $this->allocator->allocate($amount, $this->balances->forAllocator($installments), $this->balances->allocationOrder($loan));

            $transaction = $this->ledger->credit($loan, TransactionType::Repayment, $amount, $lines, [
                'value_date' => $valueDate->toDateString(),
                'channel' => PaymentChannel::from($data['channel']),
                'external_reference' => $data['external_reference'] ?? null,
                'payer_customer_id' => $payer?->customer_id,
                'idempotency_key' => $keyHash,
                'narration' => $data['narration'] ?? 'Repayment',
                'posted_by' => $by->id,
                'approved_by' => $approvedBy?->id,
            ]);

            // G-3: the guarantor has paid for the borrower.
            if ($payer?->role === PartyRole::Guarantor && $payer->invoked_at === null) {
                $payer->forceFill(['invoked_at' => now()])->save();
            }

            $this->balances->refresh($loan);
            $this->balances->closeIfPaid($loan, $by);

            $this->logActivity('PAYMENT', 'Loan', "Payment {$transaction->txn_no} of {$amount} on {$loan->application_no}");
            PaymentPosted::dispatch($transaction);

            return $transaction;
        }, attempts: 3);
    }

    /**
     * Ask for a reversal (maker). The reversal itself is posted when a second person approves it.
     */
    public function requestReversal(LoanTransaction $transaction, string $reason, User $by): ApprovalRequest
    {
        $this->assertReversible($transaction);
        $this->approvals->assertNonePending($transaction, ApprovalAction::PaymentReversal);

        return $this->approvals->create($transaction, ApprovalAction::PaymentReversal, ['txn_no' => $transaction->txn_no, 'amount' => (string) $transaction->amount], $reason, $by);
    }

    /**
     * Post the contra entry: same lines with negative amounts. The original is never changed.
     */
    public function reverse(LoanTransaction $transaction, string $reason, User $approver, int $requestedBy): LoanTransaction
    {
        $loan = $this->workflow->lock($transaction->loan()->withoutGlobalScope('branch')->first());
        $transaction = LoanTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
        $this->assertReversible($transaction);

        $lines = $transaction->allocations->map(fn ($a) => new AllocationLine($a->loan_installment_id, $a->component, Money::of($a->amount)->negated()))->all();

        $reversal = $this->ledger->credit($loan, TransactionType::Reversal, Money::of($transaction->amount), $lines, [
            'value_date' => BusinessDate::today()->toDateString(),
            'channel' => $transaction->channel,
            'external_reference' => $transaction->external_reference,
            'payer_customer_id' => $transaction->payer_customer_id,
            'reverses_transaction_id' => $transaction->id,
            'narration' => "Reversal of {$transaction->txn_no}: {$reason}",
            'posted_by' => $requestedBy,
            'approved_by' => $approver->id,
        ]);

        $this->clearInvocationIfNoPayment($loan, $transaction->payer_customer_id);
        $this->balances->refresh($loan);

        return $reversal;
    }

    protected function assertReversible(LoanTransaction $transaction): void
    {
        if ($transaction->type !== TransactionType::Repayment) {
            throw new BusinessRuleException('NOT_REVERSIBLE', 'Only repayments can be reversed.', 422);
        }

        if ($transaction->reversal()->exists()) {
            throw new BusinessRuleException('ALREADY_REVERSED', 'This payment has already been reversed.', 409);
        }

        // A closed loan has released its guarantors and collateral; reopening it is a manual correction.
        if ($transaction->loan()->withoutGlobalScope('branch')->first()?->status !== LoanStatus::Active) {
            throw new BusinessRuleException('LOAN_NOT_ACTIVE', 'Payments can only be reversed while the loan is active.', 409);
        }
    }

    protected function assertPayable(Loan $loan): void
    {
        if ($loan->isGroupFacility()) {
            throw new BusinessRuleException('PAY_MEMBER_LOAN', 'Each member repays their own loan. Post the payment on the member loan.', 422);
        }

        if ($loan->status !== LoanStatus::Active) {
            throw new BusinessRuleException('LOAN_NOT_ACTIVE', 'Payments can only be posted on an active loan.', 409);
        }

        if ($loan->approvalRequests()->pending()->where('action', ApprovalAction::Restructure->value)->exists()) {
            throw new BusinessRuleException('RESTRUCTURE_PENDING', 'A restructure is waiting for approval. Payments resume once it is decided.', 409);
        }
    }

    /**
     * Who actually paid: a borrower or a guarantor of this loan (nobody else).
     */
    protected function payer(Loan $loan, ?string $customerPublicId): ?LoanParty
    {
        if ($customerPublicId === null) {
            return null;
        }

        $customerId = Customer::withoutGlobalScope('branch')->where('public_id', $customerPublicId)->value('id');
        $party = $customerId ? $loan->parties()->where('customer_id', $customerId)->first() : null;

        // A group member's guarantors sit on the group facility.
        if (! $party && $customerId && $loan->isMemberLoan()) {
            $party = LoanParty::where('loan_id', $loan->parent_id)->where('customer_id', $customerId)->where('role', PartyRole::Guarantor->value)->first();
        }

        if (! $party) {
            throw new BusinessRuleException('PAYER_NOT_PARTY', 'The payer must be a borrower or guarantor of this loan.', 422);
        }

        return $party;
    }

    /**
     * If the reversed payment was the guarantor's only one, they did not actually pay.
     */
    protected function clearInvocationIfNoPayment(Loan $loan, ?int $payerId): void
    {
        if ($payerId === null) {
            return;
        }

        $stillPaid = LoanTransaction::where('loan_id', $loan->id)
            ->where('type', TransactionType::Repayment->value)
            ->where('payer_customer_id', $payerId)
            ->whereDoesntHave('reversal')
            ->exists();

        if (! $stillPaid) {
            LoanParty::whereIn('loan_id', array_filter([$loan->id, $loan->parent_id]))
                ->where('customer_id', $payerId)
                ->where('role', PartyRole::Guarantor->value)
                ->update(['invoked_at' => null]);
        }
    }

    protected function keyFor(Loan $loan, string $idempotencyKey): string
    {
        return hash('sha256', "repayment|{$loan->id}|{$idempotencyKey}");
    }
}
