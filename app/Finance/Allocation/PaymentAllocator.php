<?php

declare(strict_types=1);

namespace App\Finance\Allocation;

use App\Enums\AllocationComponent;
use App\Finance\Support\Money;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Decides where every rupee of a payment goes (the payment waterfall).
 *
 * 1. The oldest unpaid installment is settled first, so arrears are always cleared
 *    before money goes to later installments.
 * 2. Inside an installment, components are paid in the given order
 *    (conventional default: penalty → charge → profit → principal).
 * 3. Anything left after every due amount is covered becomes an "advance" credit.
 *
 * The allocated lines always add up to exactly the payment amount; RepaymentService
 * checks this again before it writes the ledger.
 */
class PaymentAllocator
{
    /**
     * @param  list<OutstandingInstallment>  $installments
     * @param  list<AllocationComponent>  $order
     * @return list<AllocationLine>
     */
    public function allocate(BigDecimal|string $amount, array $installments, array $order): array
    {
        $remaining = Money::of($amount);

        if (! $remaining->isGreaterThan(0)) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        if (in_array(AllocationComponent::Advance, $order, true)) {
            throw new InvalidArgumentException('Advance is not a due component and cannot be in the allocation order.');
        }

        usort($installments, function (OutstandingInstallment $a, OutstandingInstallment $b) {
            // Oldest due date first; the reference keeps the order stable for equal dates.
            return [$a->dueDate->timestamp, (string) $a->reference] <=> [$b->dueDate->timestamp, (string) $b->reference];
        });

        $lines = [];

        foreach ($installments as $installment) {
            foreach ($order as $component) {
                if ($remaining->isZero()) {
                    break 2;
                }

                $due = $installment->amountFor($component);

                if (! $due->isGreaterThan(0)) {
                    continue;
                }

                $applied = Money::min($due, $remaining);
                $lines[] = new AllocationLine($installment->reference, $component, $applied);
                $remaining = $remaining->minus($applied);
            }
        }

        if ($remaining->isGreaterThan(0)) {
            $lines[] = new AllocationLine(null, AllocationComponent::Advance, $remaining);
        }

        return $lines;
    }
}
