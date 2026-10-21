<?php

namespace App\Finance\Schedule;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * One installment of a schedule. "profit" holds interest (conventional) or profit/rent (Islamic).
 */
final class ScheduleLine
{
    public function __construct(
        public readonly int $installmentNo,
        public readonly CarbonImmutable $dueDate,
        public readonly BigDecimal $openingBalance,
        public readonly BigDecimal $principal,
        public readonly BigDecimal $profit,
        public readonly BigDecimal $closingBalance,
    ) {}

    public function total(): BigDecimal
    {
        return $this->principal->plus($this->profit);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'installment_no' => $this->installmentNo,
            'due_date' => $this->dueDate->toDateString(),
            'opening_balance' => (string) $this->openingBalance,
            'principal' => (string) $this->principal,
            'profit' => (string) $this->profit,
            'total' => (string) $this->total(),
            'closing_balance' => (string) $this->closingBalance,
        ];
    }
}
