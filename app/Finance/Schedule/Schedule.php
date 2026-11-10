<?php

declare(strict_types=1);

namespace App\Finance\Schedule;

use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * The result of a pricing method: the installment lines plus the contract totals.
 */
final class Schedule
{
    /**
     * @param  list<ScheduleLine>  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly BigDecimal $residualValue,
    ) {}

    public function totalPrincipal(): BigDecimal
    {
        return Money::sum(array_map(fn (ScheduleLine $line) => $line->principal, $this->lines));
    }

    public function totalProfit(): BigDecimal
    {
        return Money::sum(array_map(fn (ScheduleLine $line) => $line->profit, $this->lines));
    }

    /**
     * What the customer pays over the life of the contract (excluding fees and penalties).
     */
    public function totalPayable(): BigDecimal
    {
        return $this->totalPrincipal()->plus($this->totalProfit())->plus($this->residualValue);
    }

    /**
     * The regular installment. The last line can differ by a few cents because it
     * absorbs rounding, so the first line is the one shown to the customer.
     */
    public function installmentAmount(): BigDecimal
    {
        return $this->lines[0]->total();
    }

    public function lastLine(): ScheduleLine
    {
        return $this->lines[array_key_last($this->lines)];
    }

    public function maturityDate(): CarbonImmutable
    {
        return $this->lastLine()->dueDate;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'installment_amount' => (string) $this->installmentAmount(),
            'total_principal' => (string) $this->totalPrincipal(),
            'total_profit' => (string) $this->totalProfit(),
            'residual_value' => (string) $this->residualValue,
            'total_payable' => (string) $this->totalPayable(),
            'maturity_date' => $this->maturityDate()->toDateString(),
            'lines' => array_map(fn (ScheduleLine $line) => $line->toArray(), $this->lines),
        ];
    }
}
