<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sensitive changes that need a second person (maker-checker). Each action names the
 * permission the approver must hold. More actions (write-off, customer correction …) are added
 * as their modules are built.
 */
enum ApprovalAction: string
{
    // A new Islamic product goes live only after the Shariah board approves it (D-5).
    case ProductShariahApproval = 'product_shariah_approval';

    // Changing the pricing terms or charges of an approved Islamic product.
    case ProductTermsChange = 'product_terms_change';

    // Undo a posted repayment (bounced cheque, wrong loan …).
    case PaymentReversal = 'payment_reversal';

    // A repayment dated further back than payment.max_backdate_days.
    case BackdatedPayment = 'backdated_payment';

    // Forgive (part of) the late charges of an installment.
    case PenaltyWaiver = 'penalty_waiver';

    // A new repayment schedule for a loan in difficulty.
    case Restructure = 'restructure';

    // A journal typed in by a person (the posting rules write all the others).
    case ManualJournal = 'manual_journal';

    // Take a non-performing loan off the books; recovery goes on.
    case WriteOff = 'loan_write_off';

    public function permission(): string
    {
        return match ($this) {
            self::ProductShariahApproval, self::ProductTermsChange => 'Product Shariah Approve',
            self::PaymentReversal => 'Payment Reversal Approve',
            self::BackdatedPayment => 'Payment Backdate Approve',
            self::PenaltyWaiver => 'Penalty Waiver Approve',
            self::Restructure => 'Loan Restructure Approve',
            self::ManualJournal => 'GL Journal Approve',
            self::WriteOff => 'Loan Write Off Approve',
        };
    }

    /**
     * Shariah decisions must quote the board resolution / reference number.
     */
    public function requiresReference(): bool
    {
        return in_array($this, [self::ProductShariahApproval, self::ProductTermsChange], true);
    }
}
