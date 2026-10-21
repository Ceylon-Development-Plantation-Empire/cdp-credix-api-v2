<?php

namespace App\Services\Loans;

use App\Enums\AllocationComponent;
use App\Enums\ChargeCalculation;
use App\Enums\ChargeType;
use App\Enums\ClosureReason;
use App\Enums\FinanceBasis;
use App\Enums\LoanStatus;
use App\Enums\PaymentChannel;
use App\Enums\TransactionType;
use App\Events\PaymentPosted;
use App\Exceptions\BusinessRuleException;
use App\Finance\Allocation\AllocationLine;
use App\Finance\Charges\ChargeCalculator;
use App\Finance\Settlement\EarlySettlementCalculator;
use App\Finance\Support\Money;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanTransaction;
use App\Models\User;
use App\Traits\ActivityLogTrait;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Closing a loan before maturity (design 6.1 / 6.2).
 *
 * Conventional: arrears + principal not yet due + interest accrued since the last due date
 *               + the product's early settlement fee. Future interest is not charged.
 * Islamic:      arrears + remaining sale price − ibra' (the Shariah-approved rebate % of the
 *               profit not yet earned). No accrual and no fee: the price is fixed at contract.
 *
 * The interest (or profit) the customer no longer owes is booked as a waiver, so the schedule
 * keeps its original figures and the statement shows exactly what was forgiven.
 */
class EarlySettlementService
{
    use ActivityLogTrait;

    public function __construct(
        protected LoanWorkflowService $workflow,
        protected LoanBalanceService $balances,
        protected LoanPricingService $pricing,
        protected LedgerService $ledger,
        protected EarlySettlementCalculator $calculator,
        protected ChargeCalculator $charges,
        protected SafekeepingFeeService $safekeeping,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function quote(Loan $loan, ?CarbonImmutable $date = null): array
    {
        $date ??= CarbonImmutable::today();
        $this->assertSettleable($loan);

        $q = $this->compute($loan, $date);

        return [
            'settlement_date' => $date->toDateString(),
            'cost_label' => $loan->finance_basis->costLabel(),
            'arrears' => (string) $q['arrears'],
            'future_principal' => (string) $q['future_principal'],
            'profit_payable' => (string) $q['profit_payable'],
            'profit_forgiven' => (string) $q['profit_forgiven'],
            'fee' => (string) $q['fee'],
            'total' => (string) $q['total'],
        ];
    }

    /**
     * @param  array{amount: string, channel: string, external_reference?: string|null}  $data
     */
    public function settle(Loan $loan, array $data, string $idempotencyKey, User $by): LoanTransaction
    {
        return DB::transaction(function () use ($loan, $data, $idempotencyKey, $by) {
            $keyHash = hash('sha256', "settlement|{$loan->id}|{$idempotencyKey}");

            if ($existing = LoanTransaction::where('idempotency_key', $keyHash)->first()) {
                return $existing;
            }

            $loan = $this->workflow->lock($loan);
            $this->assertSettleable($loan);

            $today = CarbonImmutable::today();
            // Normally raised already by the nightly run; this covers a night that failed.
            $this->safekeeping->apply($loan, $today);
            $q = $this->compute($loan, $today);

            // The customer pays the quoted figure exactly; anything else is a mistake to catch now.
            if (! Money::of($data['amount'])->isEqualTo($q['total'])) {
                throw new BusinessRuleException('SETTLEMENT_AMOUNT_MISMATCH', "The settlement amount today is {$q['total']}.", 422, ['total' => (string) $q['total']]);
            }

            /** @var Collection<int, LoanInstallment> $future */
            $future = $q['future_installments'];

            if ($q['fee']->isPositive()) {
                $target = $future->first() ?? $q['arrears_installments']->last();
                $this->ledger->raise($loan, $target, AllocationComponent::Charge, $q['fee'], $today, 'Early settlement fee', chargeType: ChargeType::EarlySettlement);
            }

            // Re-read the lines so the fee just raised is part of what the cash pays.
            $q['arrears_installments']->each->refresh();
            $future->each->refresh();

            [$cashLines, $waiverLines] = $this->lines($q['arrears_installments'], $future, $q['profit_payable']);

            $transaction = $this->ledger->credit($loan, TransactionType::EarlySettlement, $q['total'], $cashLines, [
                'value_date' => $today->toDateString(),
                'channel' => PaymentChannel::from($data['channel']),
                'external_reference' => $data['external_reference'] ?? null,
                'idempotency_key' => $keyHash,
                'narration' => 'Early settlement',
                'posted_by' => $by->id,
            ]);

            if ($q['profit_forgiven']->isPositive()) {
                $this->ledger->credit($loan, TransactionType::Waiver, $q['profit_forgiven'], $waiverLines, [
                    'value_date' => $today->toDateString(),
                    'channel' => PaymentChannel::System,
                    'narration' => $loan->finance_basis === FinanceBasis::Islamic
                        ? 'Ibra\' (rebate of unearned profit) on early settlement'
                        : 'Unearned interest not charged on early settlement',
                    'posted_by' => $by->id,
                ]);
            }

            $this->balances->refresh($loan);
            $this->balances->closeIfPaid($loan, $by, ClosureReason::EarlySettled);

            $this->logActivity('EARLY_SETTLEMENT', 'Loan', "Early settlement {$transaction->txn_no} of {$q['total']} on {$loan->application_no}");
            PaymentPosted::dispatch($transaction);

            return $transaction;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function compute(Loan $loan, CarbonImmutable $date): array
    {
        $unpaid = $this->balances->unpaidInstallments($loan);
        $arrearsInstallments = $unpaid->filter(fn (LoanInstallment $i) => $i->due_date->lessThanOrEqualTo($date))->values();
        $future = $unpaid->filter(fn (LoanInstallment $i) => $i->due_date->greaterThan($date))->values();

        $sum = fn (Collection $rows, AllocationComponent $c) => Money::sum($rows->map(fn (LoanInstallment $i) => $i->unpaid($c)));

        // Charges and late charges already on future lines are owed in full.
        $arrears = Money::sum($arrearsInstallments->map->unpaidTotal())
            ->plus($sum($future, AllocationComponent::Charge))
            ->plus($sum($future, AllocationComponent::Penalty));
        $futurePrincipal = $sum($future, AllocationComponent::Principal);
        $futureProfit = $sum($future, AllocationComponent::Profit);
        $terms = $this->pricing->termsOf($loan);

        if ($loan->finance_basis === FinanceBasis::Islamic) {
            $quote = $this->calculator->murabaha($arrears, $futurePrincipal->plus($futureProfit), $futureProfit, $terms['early_settlement_rebate_percent'] ?? '0');
            $payable = $futureProfit->minus($quote->rebate);
            $fee = Money::zero();
        } else {
            $lastDue = $loan->installments()->current()->where('due_date', '<=', $date->toDateString())->max('due_date');
            // From the last due date, else from the start of the schedule in force (a renewal or
            // restructure starts a new one), else from disbursement.
            $accrueFrom = CarbonImmutable::parse($lastDue ?? $loan->schedule_starts_on ?? $loan->disbursed_at)->startOfDay();
            $fee = $this->fee($terms, $futurePrincipal);

            $quote = $this->calculator->conventional($arrears, $futurePrincipal, $loan->rate, $accrueFrom, $date, $fee);
            // Never more than the interest the schedule still holds.
            $payable = Money::min($quote->accruedProfit, $futureProfit);
        }

        $total = $arrears->plus($futurePrincipal)->plus($payable)->plus($fee);

        return [
            'arrears' => $arrears,
            'future_principal' => $futurePrincipal,
            'profit_payable' => $payable,
            'profit_forgiven' => $futureProfit->minus($payable),
            'fee' => $fee,
            'total' => $total,
            'arrears_installments' => $arrearsInstallments,
            'future_installments' => $future,
        ];
    }

    /**
     * Cash lines: everything on past lines, then on future lines all charges, penalties and
     * principal, and the payable interest/profit oldest first. Waiver lines: the rest of the profit.
     *
     * @param  Collection<int, LoanInstallment>  $arrears
     * @param  Collection<int, LoanInstallment>  $future
     * @return array{0: list<AllocationLine>, 1: list<AllocationLine>}
     */
    protected function lines(Collection $arrears, Collection $future, BigDecimal $profitPayable): array
    {
        $cash = [];
        $waiver = [];
        $line = function (array &$into, LoanInstallment $i, AllocationComponent $c, BigDecimal $amount) {
            if ($amount->isPositive()) {
                $into[] = new AllocationLine($i->id, $c, $amount);
            }
        };

        foreach ($arrears as $i) {
            foreach ([AllocationComponent::Penalty, AllocationComponent::Charge, AllocationComponent::Profit, AllocationComponent::Principal] as $c) {
                $line($cash, $i, $c, $i->unpaid($c));
            }
        }

        $remainingProfit = $profitPayable;

        foreach ($future as $i) {
            foreach ([AllocationComponent::Penalty, AllocationComponent::Charge, AllocationComponent::Principal] as $c) {
                $line($cash, $i, $c, $i->unpaid($c));
            }

            $profit = $i->unpaid(AllocationComponent::Profit);
            $paid = Money::min($profit, $remainingProfit);
            $remainingProfit = $remainingProfit->minus($paid);

            $line($cash, $i, AllocationComponent::Profit, $paid);
            $line($waiver, $i, AllocationComponent::Profit, $profit->minus($paid));
        }

        return [$cash, $waiver];
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    protected function fee(array $terms, BigDecimal $outstandingPrincipal): BigDecimal
    {
        $charge = collect($terms['charges'])->firstWhere('charge_type', ChargeType::EarlySettlement->value);

        if (! $charge) {
            return Money::zero();
        }

        return $this->charges->amount(ChargeCalculation::from($charge['calculation']), $charge['value'], $outstandingPrincipal, $charge['min_amount'], $charge['max_amount']);
    }

    protected function assertSettleable(Loan $loan): void
    {
        if ($loan->isGroupFacility()) {
            throw new BusinessRuleException('PAY_MEMBER_LOAN', 'Each member settles their own loan.', 422);
        }

        if ($loan->status !== LoanStatus::Active) {
            throw new BusinessRuleException('LOAN_NOT_ACTIVE', 'Only an active loan can be settled.', 409);
        }

        if (! ($this->pricing->termsOf($loan)['early_settlement_allowed'] ?? true)) {
            throw new BusinessRuleException('EARLY_SETTLEMENT_NOT_ALLOWED', 'This product does not allow early settlement.', 422);
        }
    }
}
