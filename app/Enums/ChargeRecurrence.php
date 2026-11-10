<?php

declare(strict_types=1);

namespace App\Enums;

enum ChargeRecurrence: string
{
    // Charged a single time (processing fee, or a penalty charged only once per installment).
    case Once = 'once';

    // Charged with every installment (for example monthly insurance).
    case PerInstallment = 'per_installment';

    // Late payment: charged when grace ends, then again every full month the installment stays unpaid.
    case MonthlyWhileOverdue = 'monthly_while_overdue';
}
