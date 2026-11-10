<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The parts of an installment a payment can be applied to.
 *
 * "profit" is used for both conventional interest and Islamic profit/rent so the
 * ledger has one column; the API labels it by finance basis.
 */
enum AllocationComponent: string
{
    case Principal = 'principal';
    case Profit = 'profit';
    case Charge = 'charge';
    case Penalty = 'penalty';

    // Money left over after every due amount is covered; kept as a credit on the loan.
    case Advance = 'advance';

    /**
     * Default payment waterfall per basis (setting allocation.order.* can override it).
     *
     * Conventional practice recovers the cost of default first. Islamic practice settles the
     * installment (the sale price owed) first and leaves late charges, which go to charity, to last.
     *
     * @return list<self>
     */
    public static function defaultOrder(FinanceBasis $basis): array
    {
        return $basis === FinanceBasis::Islamic
            ? [self::Profit, self::Principal, self::Charge, self::Penalty]
            : [self::Penalty, self::Charge, self::Profit, self::Principal];
    }
}
