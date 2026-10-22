<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The legal documents the legal department prepares for a loan. The value is also the slug of the
 * wording (templates.slug, channel document).
 */
enum LegalDocumentType: string
{
    // Credit facility with first and second personal guarantors and a collateral schedule.
    case DirectLoanAgreement = 'direct_loan_agreement';

    // Facility secured against the borrower's investment, with surrender of the certificate.
    case LoanAgreementInvestment = 'loan_agreement_investment';

    // Loan application against an investment and the custody receipt for the legal documents.
    case LoanApplicationAcknowledgement = 'loan_application_acknowledgement';

    public function label(): string
    {
        return match ($this) {
            self::DirectLoanAgreement => 'Direct Loan Agreement With Two Guarantors',
            self::LoanAgreementInvestment => 'Loan Agreement Against Investment',
            self::LoanApplicationAcknowledgement => 'Loan Application & Acknowledgement Against Investment',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
