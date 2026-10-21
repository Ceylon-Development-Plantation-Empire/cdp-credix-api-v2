<?php

namespace App\Enums;

enum OtpPurpose: string
{
    // The customer confirms their registered details (KYC).
    case KycConfirmation = 'kyc_confirmation';

    // A borrower accepts the loan offer (amount, installment, total payable).
    case LoanAcceptance = 'loan_acceptance';

    // A guarantor agrees to guarantee a loan.
    case GuarantorConsent = 'guarantor_consent';
}
