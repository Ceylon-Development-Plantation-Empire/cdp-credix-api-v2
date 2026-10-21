<?php

namespace App\Services\Accounting;

use App\Enums\AllocationComponent;
use App\Enums\FinanceBasis;
use App\Enums\GlJournalSource;
use App\Enums\GlJournalStatus;
use App\Enums\PaymentChannel;
use App\Enums\TransactionType;
use App\Finance\Support\Money;
use App\Models\GlAccount;
use App\Models\GlJournal;
use App\Models\GlJournalLine;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanTransaction;
use App\Models\Setting;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Posting rules: how each loan movement lands in the general ledger.
 *
 * Basis of recognition
 * - principal is a receivable from disbursement until repaid or written off;
 * - interest / profit becomes income on the installment's due date (nightly run); profit paid
 *   before its due date (early settlement, paying ahead) goes straight to income;
 * - a loan gl.suspend_income_dpd days behind is non-performing: the interest / profit it has
 *   recognised but not collected moves to "in suspense", new amounts falling due go there too,
 *   and each rupee collected is released to income. When the arrears are fully cleared the rest
 *   is released and the loan earns normally again;
 * - fees and late charges become income when they are added to the loan; Islamic late charges
 *   are credited to the charity payable, never to income;
 * - conventional and Islamic receivables and income are kept in separate accounts.
 *
 * Every journal carries a unique source key, so a movement can never be posted twice.
 * Called inside the caller's transaction: if posting fails, the loan movement is not saved either.
 */
class LoanPostingService
{
    public function __construct(
        protected GeneralLedgerService $gl,
    ) {}

    /**
     * @param  BigDecimal|null  $scheduledCharges  disbursement only: charges put on the schedule (owed with installment 1)
     */
    public function postTransaction(LoanTransaction $txn, ?BigDecimal $scheduledCharges = null): ?GlJournal
    {
        $loan = Loan::withoutGlobalScope('branch')->findOrFail($txn->loan_id);
        $amount = Money::of($txn->amount);

        $entries = match ($txn->type) {
            TransactionType::Disbursement => $this->disbursement($loan, $txn, $amount, $scheduledCharges ?? Money::zero()),
            TransactionType::Charge => $txn->channel === PaymentChannel::Deduction
                // Taken out of the payout: less cash went out, the fee is earned.
                ? [$this->fundingAccount($this->disbursementChannel($loan)) => $amount, 'fee-income' => $amount->negated()]
                : ['charges-receivable' => $amount, 'fee-income' => $amount->negated()],
            TransactionType::Penalty => ['penalty-receivable' => $amount, ($txn->is_charity ? 'charity-payable' : 'penalty-income') => $amount->negated()],
            TransactionType::Repayment, TransactionType::EarlySettlement => $this->payment($loan, $txn),
            TransactionType::Waiver => $this->waiver($loan, $txn),
            TransactionType::Reversal => $this->reversedType($txn) === TransactionType::Waiver ? $this->waiver($loan, $txn) : $this->payment($loan, $txn),
            TransactionType::WriteOff => $this->writeOff($loan, $txn),
            // Money collected after a write-off: the debt is already out of the books.
            TransactionType::Recovery => [$this->fundingAccount($txn->channel) => $amount, 'bad-debt-recovered' => $amount->negated()],
        };

        $lines = $this->gl->linesFrom($entries);

        if ($lines === []) {
            return null;
        }

        return $this->gl->write(GlJournalSource::LoanTransaction, $txn->value_date, "{$txn->narration} - {$loan->loan_no}", $lines, [
            'source_key' => "txn:{$txn->id}",
            'branch_id' => $loan->branch_id,
            'loan_id' => $loan->id,
            'loan_transaction_id' => $txn->id,
            'created_by' => $txn->posted_by,
        ]);
    }

    /**
     * Nightly: the interest / profit still unpaid on installments that have fallen due becomes
     * income (or suspense, for a non-performing loan) and a receivable. One journal per
     * installment, so a re-run posts nothing new.
     */
    public function recogniseDueProfit(Loan $loan, CarbonImmutable $date): int
    {
        $installments = $loan->installments()->current()
            ->where('due_date', '<', $date->addDay()->toDateString())
            ->whereColumn('profit_due', '>', 'profit_paid')
            ->orderBy('due_date')
            ->get();

        $done = $this->recognisedIds($installments->pluck('id'));
        $posted = 0;

        foreach ($installments as $installment) {
            if ($done->contains($installment->id)) {
                continue;
            }

            $unpaid = $installment->unpaid(AllocationComponent::Profit);

            $this->gl->write(GlJournalSource::InstallmentDue, $installment->due_date, "{$loan->finance_basis->costLabel()} due on installment {$installment->installment_no} - {$loan->loan_no}", $this->gl->linesFrom([
                $this->profitReceivable($loan) => $unpaid,
                $this->recognisedIncome($loan) => $unpaid->negated(),
            ]), [
                'source_key' => "due:{$installment->id}",
                'branch_id' => $loan->branch_id,
                'loan_id' => $loan->id,
            ]);
            $posted++;
        }

        return $posted;
    }

    /**
     * Nightly, after days past due are rebuilt: suspend a loan that has become non-performing,
     * or release the suspense of one whose arrears are fully cleared.
     */
    public function evaluateSuspension(Loan $loan, CarbonImmutable $date): void
    {
        $threshold = (int) Setting::value('gl.suspend_income_dpd', 90);

        if (! $this->isSuspended($loan) && $loan->days_past_due >= $threshold) {
            $recognised = $this->recognisedUnpaid($loan);

            if ($recognised->isPositive()) {
                $this->gl->write(GlJournalSource::IncomeSuspension, $date, "{$loan->finance_basis->costLabel()} suspended at {$loan->days_past_due} days past due - {$loan->loan_no}", $this->gl->linesFrom([
                    $this->profitIncome($loan) => $recognised,
                    $this->suspenseAccount($loan) => $recognised->negated(),
                ]), [
                    'source_key' => "suspend:{$loan->id}:{$date->toDateString()}",
                    'branch_id' => $loan->branch_id,
                    'loan_id' => $loan->id,
                ]);
            }

            $loan->forceFill(['income_suspended_at' => $date->toDateString()])->save();

            return;
        }

        if ($this->isSuspended($loan) && $loan->days_past_due === 0) {
            $this->releaseSuspense($loan, $date, 'arrears cleared');
        }
    }

    /**
     * What is left in suspense becomes income and the loan earns normally again (arrears cleared,
     * or the loan paid off). A written-off loan never gets here: its suspense is used by the write-off.
     */
    public function releaseSuspense(Loan $loan, CarbonImmutable $date, string $why): void
    {
        if (! $this->isSuspended($loan)) {
            return;
        }

        // Credit balance held for this loan, as a positive amount.
        $held = $this->loanBalance($loan, $this->suspenseAccount($loan))->negated();

        if ($held->isPositive()) {
            $this->gl->write(GlJournalSource::IncomeSuspension, $date, "{$loan->finance_basis->costLabel()} released from suspense, {$why} - {$loan->loan_no}", $this->gl->linesFrom([
                $this->suspenseAccount($loan) => $held,
                $this->profitIncome($loan) => $held->negated(),
            ]), [
                'source_key' => "release:{$loan->id}:{$date->toDateString()}",
                'branch_id' => $loan->branch_id,
                'loan_id' => $loan->id,
            ]);
        }

        $loan->forceFill(['income_suspended_at' => null])->save();
    }

    /**
     * A new schedule replaces the unpaid lines. Profit already recognised on them is taken back
     * (the new lines recognise it again when they fall due); conventional interest that was due
     * and is capitalised becomes principal. For a non-performing loan both go through suspense,
     * so capitalising arrears never creates income.
     *
     * @param  Collection<int, LoanInstallment>  $rescheduled  the unpaid lines, before they are replaced
     */
    public function postRestructure(Loan $loan, Collection $rescheduled, BigDecimal $capitalisedProfit, int $version): ?GlJournal
    {
        $recognised = $this->recognisedIds($rescheduled->pluck('id'));
        $taken = Money::sum($rescheduled->filter(fn (LoanInstallment $i) => $recognised->contains($i->id))->map(fn (LoanInstallment $i) => $i->unpaid(AllocationComponent::Profit)));

        $entries = [];
        $this->add($entries, $this->recognisedIncome($loan), $taken);
        $this->add($entries, $this->profitReceivable($loan), $taken->negated());
        $this->add($entries, $this->principalReceivable($loan), $capitalisedProfit);
        $this->add($entries, $this->recognisedIncome($loan), $capitalisedProfit->negated());

        $lines = $this->gl->linesFrom($entries);

        if ($lines === []) {
            return null;
        }

        return $this->gl->write(GlJournalSource::Restructure, CarbonImmutable::today(), "Restructured to schedule version {$version} - {$loan->loan_no}", $lines, [
            'source_key' => "restructure:{$loan->id}:{$version}",
            'branch_id' => $loan->branch_id,
            'loan_id' => $loan->id,
        ]);
    }

    /**
     * Ids of the installments whose profit has been recognised.
     *
     * @param  Collection<int, int>  $installmentIds
     * @return Collection<int, int>
     */
    public function recognisedIds(Collection $installmentIds): Collection
    {
        if ($installmentIds->isEmpty()) {
            return collect();
        }

        return GlJournal::withoutGlobalScope('branch')
            ->whereIn('source_key', $installmentIds->map(fn ($id) => "due:{$id}"))
            ->pluck('source_key')
            ->map(fn (string $key) => (int) substr($key, 4));
    }

    /**
     * Debit-positive balance of one account for one loan (posted journals only).
     */
    public function loanBalance(Loan $loan, string $slug): BigDecimal
    {
        $row = GlJournalLine::query()
            ->join('gl_journals', 'gl_journals.id', '=', 'gl_journal_lines.gl_journal_id')
            ->where('gl_journals.status', GlJournalStatus::Posted->value)
            ->where('gl_journals.loan_id', $loan->id)
            ->where('gl_journal_lines.gl_account_id', GlAccount::where('slug', $slug)->value('id'))
            ->selectRaw('SUM(gl_journal_lines.debit) - SUM(gl_journal_lines.credit) as balance')
            ->first();

        return Money::of((string) ($row->balance ?? 0));
    }

    public function isSuspended(Loan $loan): bool
    {
        return $loan->income_suspended_at !== null;
    }

    /**
     * @return array<string, BigDecimal>
     */
    protected function disbursement(Loan $loan, LoanTransaction $txn, BigDecimal $amount, BigDecimal $scheduledCharges): array
    {
        $entries = [];
        $this->add($entries, $this->principalReceivable($loan), $amount);
        $this->add($entries, $this->fundingAccount($txn->channel), $amount->negated());
        $this->add($entries, 'charges-receivable', $scheduledCharges);
        $this->add($entries, 'fee-income', $scheduledCharges->negated());

        return $entries;
    }

    /**
     * Money in: debit cash / bank, credit what each allocation line paid. A reversal has negative
     * lines, so the same rule takes everything back. On a non-performing loan, recognised profit
     * collected is also released from suspense to income.
     *
     * @return array<string, BigDecimal>
     */
    protected function payment(Loan $loan, LoanTransaction $txn): array
    {
        $allocations = $txn->allocations()->get();
        $recognised = $this->recognisedIds($allocations->pluck('loan_installment_id')->filter()->unique()->values());
        $suspended = $this->isSuspended($loan);
        $entries = [];

        foreach ($allocations as $a) {
            $amount = Money::of($a->amount);
            $isRecognised = $recognised->contains($a->loan_installment_id);

            $this->add($entries, $this->fundingAccount($txn->channel), $amount);
            $this->add($entries, $this->paidAccount($loan, $a->component, $isRecognised), $amount->negated());

            if ($suspended && $isRecognised && $a->component === AllocationComponent::Profit) {
                $this->add($entries, $this->suspenseAccount($loan), $amount);
                $this->add($entries, $this->profitIncome($loan), $amount->negated());
            }
        }

        return $entries;
    }

    /**
     * Forgiven, not paid: the income (or suspense, or charity payable) booked for it is taken
     * back. Profit never recognised nets to zero and posts nothing.
     *
     * @return array<string, BigDecimal>
     */
    protected function waiver(Loan $loan, LoanTransaction $txn): array
    {
        $allocations = $txn->allocations()->get();
        $recognised = $this->recognisedIds($allocations->pluck('loan_installment_id')->filter()->unique()->values());
        $entries = [];

        foreach ($allocations as $a) {
            $amount = Money::of($a->amount);
            $isRecognised = $recognised->contains($a->loan_installment_id);

            $forgivenFrom = match ($a->component) {
                AllocationComponent::Principal => 'loans-written-off',
                AllocationComponent::Profit => $isRecognised ? $this->recognisedIncome($loan) : $this->profitIncome($loan),
                AllocationComponent::Charge => 'fee-income',
                AllocationComponent::Penalty => $this->penaltyIsCharity($loan) ? 'charity-payable' : 'penalty-income',
                AllocationComponent::Advance => throw new LogicException('An advance cannot be waived.'),
            };

            $this->add($entries, $forgivenFrom, $amount);
            $this->add($entries, $this->paidAccount($loan, $a->component, $isRecognised), $amount->negated());
        }

        return $entries;
    }

    /**
     * Everything still owed comes off the books. Interest, charges and late charges are taken
     * back like a waiver. The principal is charged first to what is left in suspense (interest
     * capitalised by a restructure), then to the loan's provision, and only the rest to expense.
     *
     * @return array<string, BigDecimal>
     */
    protected function writeOff(Loan $loan, LoanTransaction $txn): array
    {
        $entries = $this->waiver($loan, $txn);
        $principal = ($entries['loans-written-off'] ?? Money::zero());

        $suspense = $this->suspenseAccount($loan);
        $suspenseLeft = $this->loanBalance($loan, $suspense)->negated()->minus($entries[$suspense] ?? Money::zero());
        $provision = $this->loanBalance($loan, 'loan-loss-provision')->negated();

        foreach ([$suspense => $suspenseLeft, 'loan-loss-provision' => $provision] as $slug => $available) {
            $used = Money::min($principal, Money::max($available, Money::zero()));

            if ($used->isPositive()) {
                $this->add($entries, $slug, $used);
                $this->add($entries, 'loans-written-off', $used->negated());
                $principal = $principal->minus($used);
            }
        }

        return $entries;
    }

    /**
     * Interest / profit recognised on the loan and still unpaid.
     */
    protected function recognisedUnpaid(Loan $loan): BigDecimal
    {
        $installments = $loan->installments()->current()->whereColumn('profit_due', '>', 'profit_paid')->get();
        $recognised = $this->recognisedIds($installments->pluck('id'));

        return Money::sum($installments->filter(fn (LoanInstallment $i) => $recognised->contains($i->id))->map(fn (LoanInstallment $i) => $i->unpaid(AllocationComponent::Profit)));
    }

    /**
     * The account a paid (or waived) component is credited to.
     */
    protected function paidAccount(Loan $loan, AllocationComponent $component, bool $profitRecognised): string
    {
        return match ($component) {
            AllocationComponent::Principal => $this->principalReceivable($loan),
            AllocationComponent::Profit => $profitRecognised ? $this->profitReceivable($loan) : $this->profitIncome($loan),
            AllocationComponent::Charge => 'charges-receivable',
            AllocationComponent::Penalty => 'penalty-receivable',
            AllocationComponent::Advance => 'customer-advances',
        };
    }

    protected function fundingAccount(PaymentChannel $channel): string
    {
        return match ($channel) {
            PaymentChannel::Cash => 'cash-in-hand',
            PaymentChannel::BankTransfer, PaymentChannel::Cheque, PaymentChannel::Online => 'bank',
            default => throw new LogicException("Money does not move through the {$channel->value} channel."),
        };
    }

    protected function disbursementChannel(Loan $loan): PaymentChannel
    {
        return LoanTransaction::where('loan_id', $loan->id)->where('type', TransactionType::Disbursement->value)->value('channel')
            ?? throw new LogicException('Charges were deducted from a loan that was never disbursed.');
    }

    protected function reversedType(LoanTransaction $txn): ?TransactionType
    {
        return LoanTransaction::whereKey($txn->reverses_transaction_id)->first()?->type;
    }

    /**
     * One penalty rule per product, so the loan's late charges are all charity or all income.
     */
    protected function penaltyIsCharity(Loan $loan): bool
    {
        return LoanTransaction::where('loan_id', $loan->id)->where('type', TransactionType::Penalty->value)->where('is_charity', true)->exists();
    }

    protected function principalReceivable(Loan $loan): string
    {
        return $loan->finance_basis === FinanceBasis::Islamic ? 'islamic-financing-receivable' : 'loans-receivable';
    }

    protected function profitReceivable(Loan $loan): string
    {
        return $loan->finance_basis === FinanceBasis::Islamic ? 'profit-receivable' : 'interest-receivable';
    }

    protected function profitIncome(Loan $loan): string
    {
        return $loan->finance_basis === FinanceBasis::Islamic ? 'profit-income' : 'interest-income';
    }

    protected function suspenseAccount(Loan $loan): string
    {
        return $loan->finance_basis === FinanceBasis::Islamic ? 'profit-suspense' : 'interest-suspense';
    }

    /**
     * Where recognised interest / profit sits: income, or suspense while the loan is non-performing.
     */
    protected function recognisedIncome(Loan $loan): string
    {
        return $this->isSuspended($loan) ? $this->suspenseAccount($loan) : $this->profitIncome($loan);
    }

    /**
     * @param  array<string, BigDecimal>  $entries
     */
    protected function add(array &$entries, string $slug, BigDecimal $amount): void
    {
        $entries[$slug] = ($entries[$slug] ?? Money::zero())->plus($amount);
    }
}
