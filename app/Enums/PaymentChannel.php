<?php

namespace App\Enums;

enum PaymentChannel: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Online = 'online';

    // Taken out of the loan amount itself (upfront charges deducted at disbursement).
    case Deduction = 'deduction';

    // A book entry with no money moving: a penalty raised by the nightly run, a waiver, a rebate.
    case System = 'system';

    /**
     * Channels that need a bank / cheque reference so the money can be traced.
     */
    public function needsReference(): bool
    {
        return in_array($this, [self::BankTransfer, self::Cheque, self::Online], true);
    }
}
