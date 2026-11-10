<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two worlds a product can live in.
 *
 * It is set once on the root of the product category tree (General / Islamic)
 * and every category and product below it inherits it. The basis decides which
 * pricing methods are allowed and how late charges are treated.
 */
enum FinanceBasis: string
{
    case Conventional = 'conventional';
    case Islamic = 'islamic';

    public function label(): string
    {
        return match ($this) {
            self::Conventional => 'General',
            self::Islamic => 'Islamic (Shariah)',
        };
    }

    /**
     * What the cost of finance is called on screens and statements.
     * Islamic contracts never charge "interest", they earn "profit" or "rent".
     */
    public function costLabel(): string
    {
        return $this === self::Islamic ? 'Profit' : 'Interest';
    }

    /**
     * Islamic products need a Shariah board approval before they go live (decision D-5).
     */
    public function requiresShariahApproval(): bool
    {
        return $this === self::Islamic;
    }
}
