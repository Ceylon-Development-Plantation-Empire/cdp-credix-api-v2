<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Everything the system tells customers about. The value is also the template slug.
 *
 * Each event lists the placeholders its templates may use. Rendering only ever replaces these
 * names with values the system prepared, so a template edited by staff cannot pull in other
 * data or run anything (the old system built messages from whole models).
 */
enum NotificationEvent: string
{
    case LoanApproved = 'loan.approved';
    case LoanRejected = 'loan.rejected';
    case LoanReviewLink = 'loan.review-link';
    case LoanDisbursed = 'loan.disbursed';
    case PaymentReceived = 'payment.received';
    case LoanSettled = 'loan.settled';
    case InstallmentDueReminder = 'installment.due-reminder';
    case InstallmentOverdue = 'installment.overdue';
    case RecoveryNotice = 'recovery.notice';
    case RecoveryNoticeGuarantor = 'recovery.notice-guarantor';

    /**
     * @return list<string>
     */
    public function placeholders(): array
    {
        $common = ['company_name', 'customer_name'];

        return [...$common, ...match ($this) {
            self::LoanApproved => ['application_no', 'amount', 'installment_amount', 'tenure'],
            self::LoanRejected => ['application_no'],
            self::LoanReviewLink => ['application_no', 'review_link', 'valid_hours'],
            self::LoanDisbursed => ['loan_no', 'amount', 'net_amount', 'installment_amount', 'first_due_date'],
            self::PaymentReceived => ['loan_no', 'amount', 'txn_no', 'outstanding'],
            self::LoanSettled => ['loan_no'],
            self::InstallmentDueReminder => ['loan_no', 'amount', 'due_date'],
            self::InstallmentOverdue => ['loan_no', 'arrears', 'days_past_due'],
            self::RecoveryNotice => ['loan_no', 'arrears', 'days_past_due', 'branch_name'],
            self::RecoveryNoticeGuarantor => ['loan_no', 'arrears', 'borrower_name', 'branch_name'],
        }];
    }
}
