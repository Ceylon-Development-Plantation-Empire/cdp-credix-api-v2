<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Finance\Schedule\Schedule;
use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The priced result of a loan: its schedule and the upfront charges, plus the amount columns that
 * are stored on the loan.
 */
final class LoanPricing
{
    /**
     * @param  list<array{charge_type: string, name: string, display_group: string, collection: string, amount: string}>  $charges
     */
    public function __construct(
        public readonly BigDecimal $amount,
        public readonly Schedule $schedule,
        public readonly array $charges,
        public readonly BigDecimal $deductedCharges,
        public readonly BigDecimal $firstInstallmentCharges,
    ) {}

    /**
     * Total of the upfront charges (deducted + added to the first installment).
     */
    public function upfrontTotal(): BigDecimal
    {
        return Money::sum(array_column($this->charges, 'amount'));
    }

    /**
     * Monthly cost of the new loan, for the debt service ratio. A weekly installment is turned
     * into a monthly figure: installment × periods per year / 12, kept at full precision and
     * rounded half-up to cents once at the end.
     */
    public function monthlyInstallment(int $periodsPerYear): BigDecimal
    {
        return Money::round($this->schedule->installmentAmount()->multipliedBy($periodsPerYear)->dividedBy(12, Money::PRECISION, RoundingMode::HALF_UP));
    }

    /**
     * The upfront charges grouped by their display name (owner decision: one combined line such as
     * "Service charges", with the itemised breakdown underneath for a customer who asks).
     *
     * @return list<array{group: string, total: string, items: list<array{charge_type: string, name: string, amount: string}>}>
     */
    public function chargeBreakdown(): array
    {
        $groups = [];

        foreach ($this->charges as $charge) {
            $group = $charge['display_group'];
            $groups[$group] ??= ['group' => $group, 'total' => Money::zero(), 'items' => []];
            $groups[$group]['total'] = $groups[$group]['total']->plus($charge['amount']);
            $groups[$group]['items'][] = ['charge_type' => $charge['charge_type'], 'name' => $charge['name'], 'amount' => $charge['amount']];
        }

        return array_values(array_map(fn (array $g) => ['group' => $g['group'], 'total' => (string) $g['total'], 'items' => $g['items']], $groups));
    }

    /**
     * The amount columns stored on the loan.
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'financed_amount' => (string) $this->amount,
            'profit_amount' => (string) $this->schedule->totalProfit(),
            'installment_amount' => (string) $this->schedule->installmentAmount(),
            'upfront_charges' => (string) $this->upfrontTotal(),
            'net_disbursement_amount' => (string) $this->amount->minus($this->deductedCharges),
            'residual_value' => (string) $this->schedule->residualValue,
        ];
    }

    /**
     * Summary shown with an offer and stored as what the borrower accepted.
     *
     * DECISION: the breakdown is not part of this summary: the summary is hashed into the
     * borrower's consent, and it must stay exactly the figures the borrower agreed to.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'amount' => (string) $this->amount,
            'installment_amount' => (string) $this->schedule->installmentAmount(),
            'installments' => count($this->schedule->lines),
            'total_profit' => (string) $this->schedule->totalProfit(),
            'total_payable' => (string) $this->schedule->totalPayable()->plus($this->firstInstallmentCharges),
            'upfront_charges' => (string) $this->upfrontTotal(),
            'net_disbursement_amount' => (string) $this->amount->minus($this->deductedCharges),
        ];
    }
}
