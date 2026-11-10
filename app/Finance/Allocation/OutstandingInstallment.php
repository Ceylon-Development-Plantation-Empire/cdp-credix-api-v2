<?php

declare(strict_types=1);

namespace App\Finance\Allocation;

use App\Enums\AllocationComponent;
use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * What is still unpaid on one installment, per component.
 * The allocator only needs these numbers, not the Eloquent model.
 */
final class OutstandingInstallment
{
    /** @var array<string, BigDecimal> */
    public readonly array $outstanding;

    /**
     * @param  int|string  $reference  Usually the loan_installments id, handed back on each allocation line.
     * @param  array<string, BigDecimal|string>  $outstanding  Keyed by AllocationComponent value.
     */
    public function __construct(
        public readonly int|string $reference,
        public readonly CarbonImmutable $dueDate,
        array $outstanding,
    ) {
        $this->outstanding = array_map(fn ($amount) => Money::of($amount), $outstanding);
    }

    public function amountFor(AllocationComponent $component): BigDecimal
    {
        return $this->outstanding[$component->value] ?? Money::zero();
    }
}
