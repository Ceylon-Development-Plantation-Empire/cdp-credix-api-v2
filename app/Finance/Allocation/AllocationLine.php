<?php

namespace App\Finance\Allocation;

use App\Enums\AllocationComponent;
use Brick\Math\BigDecimal;

final class AllocationLine
{
    public function __construct(
        // Null for the advance line, which is not tied to an installment.
        public readonly int|string|null $reference,
        public readonly AllocationComponent $component,
        public readonly BigDecimal $amount,
    ) {}
}
