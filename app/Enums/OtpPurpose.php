<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a one-time code confirms (otp_challenges.purpose).
 */
enum OtpPurpose: string
{
    // The customer confirms their registered details (KYC).
    case KycConfirmation = 'kyc_confirmation';

    // A borrower confirms the details and loan terms shown on their review link.
    case LoanReview = 'loan_review';

    // A guarantor agrees to guarantee a loan.
    case GuarantorConsent = 'guarantor_consent';

    // Second step of a staff sign-in (only while setting security.staff_login_otp is on).
    case StaffLogin = 'staff_login';

    // A staff member who forgot their password proves it is them with a code sent to their mobile.
    case PasswordReset = 'password_reset';
}
