<?php

namespace App\Services\Loans;

use App\Enums\AllocationComponent;
use App\Enums\ChargeType;
use App\Enums\PaymentChannel;
use App\Enums\TransactionType;
use App\Finance\Allocation\AllocationLine;
use App\Finance\Support\Money;
use App\Models\Loan;
use App\Models\LoanAllocation;
use App\Models\LoanInstallment;
use App\Models\LoanTransaction;
use App\Services\Accounting\LoanPostingService;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use LogicException;

/**
 * The only writer of the loan ledger.
 *
 * Two kinds of entries:
 * - credits (repayment, early settlement, waiver, reversal): a transaction with allocation lines
 *   that are applied to the installments. The lines must add up to the amount exactly;
 * - raises (penalty, charge): a new amount owed on one installment, recorded as a transaction
 *   so the statement shows when and why it appeared.
 *
 * Every entry is posted to the general ledger in the same transaction (LoanPostingService).
 *
 * Callers hold the loan row lock.
 */
class LedgerService
{
    public function __construct(
        protected LoanPostingService $posting,
    ) {}

    /**
     * @param  list<AllocationLine>  $lines  Signed amounts; negative only on a reversal.
     * @param  array<string, mixed>  $attributes  channel, value_date, payer_customer_id, idempotency_key …
     */
    public function credit(Loan $loan, TransactionType $type, BigDecimal $amount, array $lines, array $attributes): LoanTransaction
    {
        $sign = $type === TransactionType::Reversal ? -1 : 1;
        $allocated = Money::sum(array_map(fn (AllocationLine $l) => $l->amount, $lines));

        // Every rupee must be accounted for, or nothing is saved.
        if (! $allocated->isEqualTo($amount->multipliedBy($sign))) {
            throw new LogicException("Allocations ({$allocated}) do not add up to the transaction amount ({$amount}).");
        }

        $transaction = LoanTransaction::create($attributes + [
            'loan_id' => $loan->id,
            'type' => $type,
            'amount' => (string) $amount,
        ]);

        $installments = LoanInstallment::whereIn('id', array_filter(array_map(fn (AllocationLine $l) => $l->reference, $lines)))
            ->get()->keyBy('id');

        foreach ($lines as $line) {
            LoanAllocation::create([
                'loan_transaction_id' => $transaction->id,
                'loan_installment_id' => $line->reference,
                'component' => $line->component,
                'amount' => (string) $line->amount,
            ]);

            $installment = $installments[$line->reference] ?? throw new LogicException('Allocation without an installment.');

            // A waived penalty is forgiven, not paid, so it is kept apart from what the customer paid.
            if ($line->component === AllocationComponent::Penalty && $this->isWaiver($type, $transaction)) {
                $installment->penalty_waived = (string) Money::of($installment->penalty_waived)->plus($line->amount);
            } else {
                $installment->addPaid($line->component, $line->amount);
            }
        }

        $installments->each->save();
        $this->posting->postTransaction($transaction);

        return $transaction;
    }

    /**
     * A penalty or charge that becomes owed on an installment.
     */
    public function raise(Loan $loan, LoanInstallment $installment, AllocationComponent $component, BigDecimal $amount, CarbonInterface $valueDate, string $narration, bool $isCharity = false, ?ChargeType $chargeType = null, ?string $idempotencyKey = null): LoanTransaction
    {
        match ($component) {
            AllocationComponent::Penalty => $installment->penalty_due = (string) Money::of($installment->penalty_due)->plus($amount),
            AllocationComponent::Charge => $installment->charges_due = (string) Money::of($installment->charges_due)->plus($amount),
            default => throw new LogicException("Only penalties and charges are raised, not {$component->value}."),
        };

        $installment->save();

        $transaction = LoanTransaction::create([
            'loan_id' => $loan->id,
            'type' => $component === AllocationComponent::Penalty ? TransactionType::Penalty : TransactionType::Charge,
            'amount' => (string) $amount,
            'value_date' => $valueDate->toDateString(),
            'channel' => PaymentChannel::System,
            'narration' => "{$narration} (installment {$installment->installment_no})",
            'is_charity' => $isCharity,
            'charge_type' => $chargeType,
            'idempotency_key' => $idempotencyKey,
        ]);
        $this->posting->postTransaction($transaction);

        return $transaction;
    }

    /**
     * A reversal of a waiver puts the waived amount back; anything else reverses a payment.
     */
    protected function isWaiver(TransactionType $type, LoanTransaction $transaction): bool
    {
        // A write-off forgives what is left, like a waiver; nothing was paid.
        if ($type === TransactionType::Waiver || $type === TransactionType::WriteOff) {
            return true;
        }

        return $type === TransactionType::Reversal
            && LoanTransaction::whereKey($transaction->reverses_transaction_id)->first()?->type === TransactionType::Waiver;
    }
}
