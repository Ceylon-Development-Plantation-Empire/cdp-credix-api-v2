<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\AllocationComponent;
use App\Enums\ApprovalAction;
use App\Enums\CollateralStatus;
use App\Enums\InstallmentStatus;
use App\Enums\LoanActivityType;
use App\Enums\LoanStatus;
use App\Enums\PaymentChannel;
use App\Enums\PricingMethod;
use App\Enums\TransactionType;
use App\Events\PaymentPosted;
use App\Exceptions\BusinessRuleException;
use App\Finance\Allocation\AllocationLine;
use App\Finance\Collateral\GoldValuation;
use App\Finance\Pricing\GoldPawn;
use App\Finance\Schedule\LoanTerms;
use App\Finance\Support\Money;
use App\Models\Collateral;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\Collateral\CollateralTypes;
use App\Support\Idempotency;
use App\Traits\ActivityLogTrait;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gold loan renewal: the customer pays what is due today and the same gold stays pledged for
 * a new term, on the same loan (a new schedule version, like a restructure).
 *
 * What is paid
 * - interest for the days actually used (actual/365), never more than the term's interest;
 *   renewing before maturity, the interest not yet earned is not charged;
 * - unpaid charges and late charges;
 * - any principal the customer wants to pay down, and at least the amount by which the loan
 *   exceeds what the gold can now secure (re-valued at gold.rate_per_gram_24k when it is set).
 *
 * The rest of the principal runs for the new term at the product's current rate. Limits:
 * gold.max_renewals per loan, and no renewal later than gold.renewal_max_dpd days past maturity
 * (the gold is then redeemed, or the loan goes to recovery and auction).
 *
 * Rahn renews the same way with no interest at all (the rate is zero): the customer pays the
 * safekeeping fees of the months the gold was kept, and month 1 of the new term is raised.
 *
 * The GL needs nothing beyond the payment and the waiver: the principal stays a receivable and
 * the old line has no interest left on it.
 */
class GoldRenewalService
{
    use ActivityLogTrait;

    public function __construct(
        protected LoanWorkflowService $workflow,
        protected LoanBalanceService $balances,
        protected LedgerService $ledger,
        protected LoanPricingService $pricing,
        protected GoldValuation $gold,
        protected SafekeepingFeeService $safekeeping,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function quote(Loan $loan, ?int $tenure = null): array
    {
        $q = $this->compute($loan, BusinessDate::todayImmutable(), $tenure);

        return [
            'renewal_no' => $loan->renewal_count + 1,
            'principal' => (string) $q['principal'],
            'interest_payable' => (string) $q['interest_payable'],
            'interest_not_charged' => (string) $q['interest_forgiven'],
            'charges' => (string) $q['charges'],
            'late_charges' => (string) $q['penalty'],
            'gold_can_secure' => (string) $q['eligible'],
            'min_principal_payment' => (string) $q['min_principal'],
            'min_amount_due' => (string) $q['due']->plus($q['min_principal']),
            'new_rate' => $q['rate'],
            'new_tenure' => $q['tenure'],
            'new_maturity_date' => $q['maturity']->toDateString(),
        ];
    }

    /**
     * @param  array{amount: string, principal_payment?: string|null, tenure?: int|null, channel: string, external_reference?: string|null}  $data
     */
    public function renew(Loan $loan, array $data, string $idempotencyKey, User $by): LoanTransaction
    {
        $keyHash = hash('sha256', "renewal|{$loan->id}|{$idempotencyKey}");

        if ($existing = Idempotency::replay($keyHash, $data['amount'])) {
            return $existing;
        }

        return DB::transaction(function () use ($loan, $data, $keyHash, $by) {
            $loan = $this->workflow->lock($loan);

            if ($existing = Idempotency::replay($keyHash, $data['amount'])) {
                return $existing;
            }

            $today = BusinessDate::todayImmutable();
            $this->safekeeping->apply($loan, $today);
            $q = $this->compute($loan, $today, isset($data['tenure']) ? (int) $data['tenure'] : null);
            $principalPayment = Money::of($data['principal_payment'] ?? '0');

            if ($principalPayment->isLessThan($q['min_principal'])) {
                throw new BusinessRuleException('GOLD_COVER_SHORTFALL', "The gold now secures {$q['eligible']}; at least {$q['min_principal']} of the principal must be paid to renew.", 422, [
                    'min_principal_payment' => (string) $q['min_principal'],
                ]);
            }

            if ($principalPayment->isGreaterThanOrEqualTo($q['principal'])) {
                throw new BusinessRuleException('USE_REDEMPTION', 'Paying the whole principal redeems the gold. Use the settlement instead of a renewal.', 422);
            }

            $total = $q['due']->plus($principalPayment);

            if ($total->isZero()) {
                throw new BusinessRuleException('NOTHING_TO_RENEW', 'Nothing is due yet; the loan can be renewed from tomorrow.', 422);
            }

            if (! Money::of($data['amount'])->isEqualTo($total)) {
                throw new BusinessRuleException('RENEWAL_AMOUNT_MISMATCH', "The amount to renew with these terms today is {$total}.", 422, ['total' => (string) $total]);
            }

            /** @var Collection<int, LoanInstallment> $lines */
            $lines = $q['lines'];
            [$cash, $waiver] = $this->allocation($lines, $q['interest_payable'], $principalPayment);

            $transaction = $this->ledger->credit($loan, TransactionType::Repayment, $total, $cash, [
                'value_date' => $today->toDateString(),
                'channel' => PaymentChannel::from($data['channel']),
                'external_reference' => $data['external_reference'] ?? null,
                'idempotency_key' => $keyHash,
                'narration' => 'Gold loan renewal '.($loan->renewal_count + 1),
                'posted_by' => $by->id,
            ]);

            if ($q['interest_forgiven']->isPositive()) {
                $this->ledger->credit($loan, TransactionType::Waiver, $q['interest_forgiven'], $waiver, [
                    'value_date' => $today->toDateString(),
                    'channel' => PaymentChannel::System,
                    'narration' => 'Interest not charged: renewed before maturity',
                    'posted_by' => $by->id,
                ]);
            }

            $remaining = $q['principal']->minus($principalPayment);
            $this->replaceSchedule($loan, $lines, $remaining, $q['rate'], $q['tenure'], $today);
            // The gold stays in the vault: month 1 of the new term is owed from today.
            $this->safekeeping->apply($loan, $today);

            $this->workflow->record($loan, LoanActivityType::Update, $by, "Gold loan renewed ({$loan->renewal_count}) to {$loan->maturity_date->toDateString()}", [
                'transaction' => $transaction->txn_no,
                'principal' => (string) $remaining,
                'rate' => $q['rate'],
                'tenure' => $q['tenure'],
            ]);

            $this->balances->refresh($loan);
            $this->logActivity('GOLD_RENEWAL', 'Loan', "Renewed {$loan->loan_no} with {$transaction->txn_no} of {$total}");
            PaymentPosted::dispatch($transaction);

            return $transaction;
        }, attempts: 3);
    }

    /**
     * @return array<string, mixed>
     */
    protected function compute(Loan $loan, CarbonImmutable $today, ?int $tenure): array
    {
        $this->assertRenewable($loan);

        $lines = $this->balances->unpaidInstallments($loan);
        $sum = fn (AllocationComponent $c) => Money::sum($lines->map(fn (LoanInstallment $i) => $i->unpaid($c)));

        $principal = $sum(AllocationComponent::Principal);
        $profitDue = Money::sum($lines->pluck('profit_due'));
        $profitPaid = Money::sum($lines->pluck('profit_paid'));

        // Interest for the days used, capped at what the term carries; less what was already paid.
        $from = BusinessDate::of($loan->schedule_starts_on ?? $loan->disbursed_at);
        $accrued = GoldPawn::accruedInterest($principal, $loan->rate, $from, $today);
        $payable = Money::max(Money::min($accrued, $profitDue)->minus($profitPaid), Money::zero());

        $eligible = $this->goldCanSecure($loan);
        $product = $loan->product()->withTrashed()->first();
        $tenure ??= (int) $loan->tenure;

        if ($tenure < $product->min_tenure || $tenure > $product->max_tenure) {
            throw new BusinessRuleException('TENURE_OUT_OF_RANGE', "The term must be between {$product->min_tenure} and {$product->max_tenure} months.", 422);
        }

        $rate = (string) $product->default_rate;
        $maturity = $loan->pricing_method->calculator()
            ->buildSchedule(new LoanTerms($principal, $rate, $tenure, $loan->repayment_frequency, $today))
            ->maturityDate();

        $charges = $sum(AllocationComponent::Charge);
        $penalty = $sum(AllocationComponent::Penalty);

        return [
            'lines' => $lines,
            'principal' => $principal,
            'interest_payable' => $payable,
            'interest_forgiven' => $sum(AllocationComponent::Profit)->minus($payable),
            'charges' => $charges,
            'penalty' => $penalty,
            'due' => $payable->plus($charges)->plus($penalty),
            'eligible' => $eligible,
            'min_principal' => Money::max($principal->minus($eligible), Money::zero()),
            'rate' => $rate,
            'tenure' => $tenure,
            'maturity' => $maturity,
        ];
    }

    /**
     * What the pledged gold can secure now: re-valued at today's rate when head office has set
     * it, otherwise the valuation made when the loan was given.
     */
    protected function goldCanSecure(Loan $loan): BigDecimal
    {
        $rate = Setting::value('gold.rate_per_gram_24k');

        return Money::sum($this->gold($loan)->map(fn (Collateral $c) => blank($rate)
            ? Money::of($c->eligible_amount)
            : $this->gold->eligibleAmount($this->gold->value($c->details['net_weight_g'], (int) $c->details['karat'], (string) $rate), $c->ltv_percent)));
    }

    /**
     * @return Collection<int, Collateral>
     */
    protected function gold(Loan $loan): Collection
    {
        return $loan->collaterals()->whereIn('collateral_type_id', app(CollateralTypes::class)->goldIds())->where('status', CollateralStatus::Perfected->value)->get();
    }

    /**
     * Cash pays late charges, charges, the interest due and the principal paid down; the
     * interest not earned is waived.
     *
     * @param  Collection<int, LoanInstallment>  $lines
     * @return array{0: list<AllocationLine>, 1: list<AllocationLine>}
     */
    protected function allocation(Collection $lines, BigDecimal $interest, BigDecimal $principal): array
    {
        $cash = [];
        $waiver = [];

        foreach ($lines as $line) {
            foreach ([AllocationComponent::Penalty, AllocationComponent::Charge] as $component) {
                if ($line->unpaid($component)->isPositive()) {
                    $cash[] = new AllocationLine($line->id, $component, $line->unpaid($component));
                }
            }

            $profit = $line->unpaid(AllocationComponent::Profit);
            $paid = Money::min($profit, $interest);
            $interest = $interest->minus($paid);

            if ($paid->isPositive()) {
                $cash[] = new AllocationLine($line->id, AllocationComponent::Profit, $paid);
            }

            if ($profit->minus($paid)->isPositive()) {
                $waiver[] = new AllocationLine($line->id, AllocationComponent::Profit, $profit->minus($paid));
            }

            $down = Money::min($line->unpaid(AllocationComponent::Principal), $principal);
            $principal = $principal->minus($down);

            if ($down->isPositive()) {
                $cash[] = new AllocationLine($line->id, AllocationComponent::Principal, $down);
            }
        }

        return [$cash, $waiver];
    }

    /**
     * The old line keeps its history (rescheduled); the remaining principal gets a new term.
     *
     * @param  Collection<int, LoanInstallment>  $old
     */
    protected function replaceSchedule(Loan $loan, Collection $old, BigDecimal $principal, string $rate, int $tenure, CarbonImmutable $today): void
    {
        $schedule = $loan->pricing_method->calculator()->buildSchedule(new LoanTerms($principal, $rate, $tenure, $loan->repayment_frequency, $today));
        $version = (int) $loan->installments()->max('schedule_version') + 1;
        $graceDays = (int) ($this->pricing->termsOf($loan)['grace_period_days'] ?? Setting::value('repayment.grace_period_days', 7));

        LoanInstallment::whereIn('id', $old->pluck('id'))->update(['status' => InstallmentStatus::Rescheduled->value]);

        foreach ($schedule->lines as $line) {
            LoanInstallment::create([
                'loan_id' => $loan->id,
                'schedule_version' => $version,
                'installment_no' => $line->installmentNo,
                'due_date' => $line->dueDate->toDateString(),
                'principal_due' => (string) $line->principal,
                'profit_due' => (string) $line->profit,
                'charges_due' => '0',
                'penalty_due' => '0',
                'status' => InstallmentStatus::Upcoming,
                'grace_ends_on' => $line->dueDate->addDays($graceDays)->toDateString(),
            ]);
        }

        $loan->forceFill([
            'rate' => $rate,
            'tenure' => $tenure,
            'installment_amount' => (string) $schedule->lines[0]->principal->plus($schedule->lines[0]->profit),
            'maturity_date' => $schedule->maturityDate()->toDateString(),
            'schedule_starts_on' => $today->toDateString(),
            'renewal_count' => $loan->renewal_count + 1,
        ])->save();
    }

    protected function assertRenewable(Loan $loan): void
    {
        if (! in_array($loan->pricing_method, [PricingMethod::GoldPawn, PricingMethod::Rahn], true)) {
            throw new BusinessRuleException('NOT_A_GOLD_LOAN', 'Only gold loans (pawning or Rahn) can be renewed.', 422);
        }

        if ($loan->status !== LoanStatus::Active) {
            throw new BusinessRuleException('LOAN_NOT_ACTIVE', 'Only an active loan can be renewed.', 409);
        }

        $max = (int) Setting::value('gold.max_renewals', 3);

        if ($loan->renewal_count >= $max) {
            throw new BusinessRuleException('RENEWAL_LIMIT_REACHED', "This loan has been renewed {$loan->renewal_count} time(s), the most allowed. The gold must be redeemed.", 422, ['max_renewals' => $max]);
        }

        $maxDpd = (int) Setting::value('gold.renewal_max_dpd', 30);

        if ($loan->days_past_due > $maxDpd) {
            throw new BusinessRuleException('RENEWAL_TOO_LATE', "A gold loan more than {$maxDpd} days past maturity cannot be renewed.", 422);
        }

        $pending = $loan->approvalRequests()->pending()->whereIn('action', [ApprovalAction::Restructure->value, ApprovalAction::WriteOff->value])->exists();

        if ($pending) {
            throw new BusinessRuleException('APPROVAL_PENDING', 'A restructure or write-off is waiting for approval.', 409);
        }

        if ($this->gold($loan)->isEmpty()) {
            throw new BusinessRuleException('NO_GOLD_IN_CUSTODY', 'No perfected gold is pledged on this loan.', 422);
        }
    }
}
