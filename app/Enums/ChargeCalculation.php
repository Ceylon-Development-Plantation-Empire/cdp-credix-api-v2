<?php

namespace App\Enums;

/**
 * How a charge amount is worked out. Used by product charges and by the default
 * late payment penalty in settings (decision D-4: fixed or % of overdue, both supported).
 */
enum ChargeCalculation: string
{
    case Fixed = 'fixed';
    case PercentOfPrincipal = 'percent_of_principal';
    case PercentOfOverdue = 'percent_of_overdue';
    case PercentOfOutstanding = 'percent_of_outstanding';

    // Rahn / gold safekeeping: rupees per gram of gold per month.
    case PerGramPerMonth = 'per_gram_per_month';

    public function isPercentage(): bool
    {
        return in_array($this, [self::PercentOfPrincipal, self::PercentOfOverdue, self::PercentOfOutstanding], true);
    }
}
