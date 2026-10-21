<?php

namespace App\Enums;

enum TransactionType: string
{
    case Disbursement = 'disbursement';
    case Repayment = 'repayment';
    case Charge = 'charge';
    case Penalty = 'penalty';
    case Waiver = 'waiver';
    case EarlySettlement = 'early_settlement';
    case WriteOff = 'write_off';
    case Recovery = 'recovery';
    case Reversal = 'reversal';
}
