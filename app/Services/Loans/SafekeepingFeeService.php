<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\AllocationComponent;
use App\Enums\ChargeBehaviour;
use App\Enums\ChargeCalculation;
use App\Enums\CollateralStatus;
use App\Enums\LoanStatus;
use App\Finance\Collateral\GoldValuation;
use App\Finance\Support\Money;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanTransaction;
use App\Support\BusinessDate;
use App\Support\Charges\ChargeTypeRegistry;
use App\Support\Collateral\CollateralTypes;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Safekeeping fee (ujrah) for pledged gold: the product's safekeeping_fee charge, by weight and
 * time kept, never by the loan amount (for Rahn anything else would be interest by another name).
 *
 * Every month started counts in full, from the day the schedule in force began (disbursement or
 * renewal): month 1 is raised at once, each later month on its monthly anniversary by the nightly
 * run, for as long as the gold is kept. The fee goes on the open line, so a redemption, renewal
 * or final payment collects it with everything else.
 *
 *   per_gram_per_month: net weight (g) × value, clamped to the charge's min / max
 *   fixed:              value per month
 *
 * One transaction per month, with a unique key: a month can never be charged twice.
 */
class SafekeepingFeeService
{
    public function __construct(
        protected LoanPricingService $pricing,
        protected LedgerService $ledger,
        protected GoldValuation $gold,
        protected ChargeTypeRegistry $chargeTypes,
    ) {}

    /**
     * Raise every month started up to $date that is not charged yet. Callers hold the loan lock.
     *
     * @return int months raised
     */
    public function apply(Loan $loan, CarbonImmutable $date): int
    {
        $rule = collect($this->pricing->termsOf($loan)['charges'] ?? [])->firstWhere('behaviour', ChargeBehaviour::Safekeeping->value);

        if (! $rule || $loan->status !== LoanStatus::Active) {
            return 0;
        }

        $start = BusinessDate::of($loan->schedule_starts_on ?? $loan->disbursed_at);

        if ($date->lessThan($start)) {
            return 0;
        }

        $fee = $this->monthlyFee($loan, $rule);
        $line = $loan->installments()->current()->orderByDesc('due_date')->first();

        if (! $fee->isPositive() || ! $line) {
            return 0;
        }

        $started = (int) $start->diffInMonths($date->startOfDay()) + 1;

        // Each month of this schedule has its own key, so months of an earlier term never count.
        $keys = collect(range(1, $started))->mapWithKeys(fn (int $month) => [$month => hash('sha256', "safekeeping|{$loan->id}|{$start->toDateString()}|{$month}")]);
        $charged = LoanTransaction::where('loan_id', $loan->id)
            ->where('charge_type_id', $this->chargeTypes->system(ChargeBehaviour::Safekeeping)->id)
            ->whereIn('idempotency_key', $keys->values())
            ->pluck('idempotency_key');
        $raised = 0;

        foreach ($keys->diff($charged) as $month => $key) {
            /** @var LoanInstallment $line */
            $this->ledger->raise($loan, $line, AllocationComponent::Charge, $fee, $start->addMonths($month - 1), "Safekeeping fee, month {$month}", false, ChargeBehaviour::Safekeeping, $key);
            $raised++;
        }

        return $raised;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    protected function monthlyFee(Loan $loan, array $rule): BigDecimal
    {
        $fee = match (ChargeCalculation::from($rule['calculation'])) {
            ChargeCalculation::PerGramPerMonth => $this->gold->safekeepingFee($this->netWeight($loan), $rule['value'], 1),
            default => Money::of($rule['value']),
        };

        if ($rule['min_amount'] !== null) {
            $fee = Money::max($fee, Money::of($rule['min_amount']));
        }

        if ($rule['max_amount'] !== null) {
            $fee = Money::min($fee, Money::of($rule['max_amount']));
        }

        return $fee;
    }

    /**
     * Net weight of the gold in custody (perfected, not released).
     */
    protected function netWeight(Loan $loan): BigDecimal
    {
        return $loan->collaterals()
            ->whereIn('collateral_type_id', app(CollateralTypes::class)->goldIds())
            ->where('status', CollateralStatus::Perfected->value)
            ->get()
            ->reduce(fn (BigDecimal $sum, $c) => $sum->plus(BigDecimal::of((string) ($c->details['net_weight_g'] ?? '0'))), BigDecimal::zero());
    }
}
