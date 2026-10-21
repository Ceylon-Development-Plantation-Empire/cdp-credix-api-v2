<?php

namespace App\Finance\Settlement;

use Brick\Math\BigDecimal;

/**
 * Breakdown of what a customer pays to close a loan early.
 */
final class SettlementQuote
{
    public function __construct(
        // Overdue amounts that must be paid anyway (installments, charges, penalties).
        public readonly BigDecimal $arrears,
        // Principal (or remaining sale price for Murabaha) not yet due.
        public readonly BigDecimal $futureBalance,
        // Interest accrued since the last due date (conventional only).
        public readonly BigDecimal $accruedProfit,
        public readonly BigDecimal $fee,
        // Ibra' – rebate of unearned profit (Islamic only).
        public readonly BigDecimal $rebate,
    ) {}

    public function total(): BigDecimal
    {
        return $this->arrears
            ->plus($this->futureBalance)
            ->plus($this->accruedProfit)
            ->plus($this->fee)
            ->minus($this->rebate);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'arrears' => (string) $this->arrears,
            'future_balance' => (string) $this->futureBalance,
            'accrued_profit' => (string) $this->accruedProfit,
            'fee' => (string) $this->fee,
            'rebate' => (string) $this->rebate,
            'total' => (string) $this->total(),
        ];
    }
}
