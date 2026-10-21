<?php

namespace App\Services\Loans;

use App\Finance\Schedule\Schedule;
use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The priced result of a loan: its schedule and the upfront charges, plus the amount columns
 * that are stored on the loan.
 */
final class LoanPricing
{
    /**
     * @param  list<array{charge_type: string, collection: string, amount: string}>  $charges
     */
    public function __construct(
        public readonly BigDecimal $amount,
        public readonly Schedule $schedule,
        public readonly array $charges,
        public readonly BigDecimal $deductedCharges,
        public readonly BigDecimal $firstInstallmentCharges,
    ) {}

    public function upfrontTotal(): BigDecimal
    {
        return Money::sum(array_column($this->charges, 'amount'));
    }

    /**
     * Monthly cost of the new loan, for the debt service ratio. A weekly installment is
     * turned into a monthly figure (× 52 / 12).
     */
    public function monthlyInstallment(int $periodsPerYear): BigDecimal
    {
        return Money::round($this->schedule->installmentAmount()->multipliedBy($periodsPerYear)->dividedBy(12, Money::PRECISION, RoundingMode::HALF_UP));
    }

    /**
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
