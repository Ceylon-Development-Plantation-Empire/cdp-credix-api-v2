<?php

namespace App\Enums;

enum ChargeCollection: string
{
    // Taken from the loan amount before it is paid out (net disbursement = amount − charge).
    case DeductFromDisbursement = 'deduct_from_disbursement';

    // Added on top of the first installment.
    case AddToFirstInstallment = 'add_to_first_installment';

    // Raised when the event happens (late payment, early settlement, safekeeping).
    case OnEvent = 'on_event';
}
