<?php

declare(strict_types=1);

namespace App\Enums;

enum RepaymentFrequency: string
{
    case Monthly = 'monthly';
    case Fortnightly = 'fortnightly';
    case Weekly = 'weekly';

    // One payment at maturity (gold pawning, rahn).
    case Bullet = 'bullet';

    /**
     * Number of repayment periods in a year. Used to turn the annual rate into a
     * periodic rate (24% p.a. paid weekly → 24 / 52 per week).
     *
     * A bullet loan has no periods; its cost is accrued by actual days instead.
     */
    public function periodsPerYear(): ?int
    {
        return match ($this) {
            self::Monthly => 12,
            self::Fortnightly => 26,
            self::Weekly => 52,
            self::Bullet => null,
        };
    }
}
